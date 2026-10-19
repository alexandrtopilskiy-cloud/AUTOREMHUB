<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Exceptions\ApiException;
use App\Models\RepairRequest;
use App\Models\RequestChat;
use App\Models\RequestChatFile;
use App\Models\RequestChatMessage;
use App\Services\MediaService;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class RequestChatController
{
    private const PAGE_SIZE = 50;
    private const DOCUMENT_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'text/plain',
        'text/csv',
        'application/rtf',
    ];
    private const MEDIA_MIME_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic',
        'video/mp4', 'video/webm', 'video/quicktime',
    ];

    public function __construct(private readonly MediaService $media)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $chats = DB::table('request_chats as c')
            ->join('repair_requests as r', 'r.id', '=', 'c.repair_request_id')
            ->leftJoin('request_chat_reads as read', function (JoinClause $join) use ($user): void {
                $join->on('read.request_chat_id', '=', 'c.id')
                    ->where('read.user_id', '=', $user->id);
            })
            ->leftJoin('request_chat_messages as m', function (JoinClause $join) use ($user): void {
                $join->on('m.request_chat_id', '=', 'c.id')
                    ->where('m.sender_id', '<>', $user->id)
                    ->whereRaw('m.id > COALESCE(read.last_read_message_id, 0)');
            })
            ->whereIn('r.id', RepairRequest::forChatUser($user)->select('id'))
            ->groupBy('c.id', 'r.id', 'r.uuid', 'r.number')
            ->orderByDesc('r.id')
            ->select('r.uuid as request_id', 'r.number as request_number')
            ->selectRaw('COUNT(m.id) as unread_count')
            ->get()
            ->map(static fn ($row): array => [
                'requestId'     => $row->request_id,
                'requestNumber' => $row->request_number,
                'unreadCount'   => (int) $row->unread_count,
            ]);

        return response()->json([
            'chats'       => $chats,
            'unreadTotal' => $chats->sum('unreadCount'),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $document = RepairRequest::resolveChatFor($request->user(), $id);
        $chat = RequestChat::query()->where('repair_request_id', $document->id)->first();

        if ($chat === null) {
            return response()->json(['created' => false, 'messages' => [], 'hasMore' => false]);
        }

        $params = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'after'  => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($params['before'], $params['after'])) {
            throw ValidationException::withMessages([
                'before' => 'Укажите только один курсор истории',
            ]);
        }

        $query = RequestChatMessage::query()
            ->where('request_chat_id', $chat->id)
            ->with('files');

        if (isset($params['before'])) {
            $query->where('id', '<', $params['before']);
        }

        if (isset($params['after'])) {
            $query->where('id', '>', $params['after'])->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }

        $page = $query->limit(self::PAGE_SIZE + 1)->get();
        $hasMore = $page->count() > self::PAGE_SIZE;
        $messages = $page->take(self::PAGE_SIZE);

        if (!isset($params['after'])) {
            $messages = $messages->reverse()->values();
        }

        return response()->json([
            'created'  => true,
            'messages' => $messages->map(fn (RequestChatMessage $m): array => $this->present($m))->values(),
            'hasMore'  => $hasMore,
        ]);
    }

    public function create(Request $request, string $id): JsonResponse
    {
        $user = $request->user();

        [$chat, $created] = DB::transaction(function () use ($user, $id): array {
            $document = RepairRequest::resolveChatFor($user, $id, true);
            $chat = RequestChat::query()->where('repair_request_id', $document->id)->first();

            if ($chat !== null) {
                return [$chat, false];
            }

            return [RequestChat::query()->create([
                'repair_request_id' => $document->id,
                'created_by'        => $user->id,
            ]), true];
        });

        return response()->json(['created' => true, 'chatId' => $chat->uuid], $created ? 201 : 200);
    }

    public function send(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $document = RepairRequest::resolveChatFor($user, $id);
        $chat = RequestChat::query()->where('repair_request_id', $document->id)->firstOrFail();
        $validated = $request->validate([
            'text'    => ['nullable', 'string', 'max:5000'],
            'files'   => ['sometimes', 'array', 'max:10'],
            'files.*' => [
                'required', 'file', 'max:30720',
                'mimetypes:' . implode(',', [...self::MEDIA_MIME_TYPES, ...self::DOCUMENT_MIME_TYPES]),
            ],
        ]);
        $text = trim((string) ($validated['text'] ?? ''));
        $files = $validated['files'] ?? [];

        if ($text === '' && $files === []) {
            throw ValidationException::withMessages([
                'text' => 'Введите сообщение или приложите файл',
            ]);
        }

        if ($files !== []) {
            $this->media->assertConfigured();
        }

        $stored = [];

        try {
            foreach ($files as $file) {
                $mime = (string) $file->getMimeType();
                $key = sprintf(
                    'tenant-%d/requests/%s/chat/%s',
                    $document->tenant_id,
                    $document->uuid,
                    Str::uuid(),
                );

                $saved = Storage::disk('media')->putFileAs(
                    dirname($key),
                    $file,
                    basename($key),
                    ['visibility' => 'private', 'ContentType' => $mime],
                );

                if (!$saved) {
                    throw new ApiException(503, 'UPLOAD_FAILED', 'Не удалось сохранить файл чата');
                }

                $stored[] = [
                    'key'  => $key,
                    'file' => $file,
                    'mime' => $mime,
                ];
            }

            $message = DB::transaction(function () use ($chat, $user, $text, $stored): RequestChatMessage {
                $message = RequestChatMessage::query()->create([
                    'request_chat_id' => $chat->id,
                    'sender_id'       => $user->id,
                    'sender_name'     => mb_substr($user->fullName(), 0, 255),
                    'sender_role'     => $user->role->value,
                    'body'            => $text,
                ]);

                foreach ($stored as $entry) {
                    $file = $entry['file'];
                    RequestChatFile::query()->create([
                        'request_chat_message_id' => $message->id,
                        'disk'                    => 'media',
                        'object_key'              => $entry['key'],
                        'original_name'           => mb_substr(basename($file->getClientOriginalName()), 0, 200),
                        'mime_type'               => $entry['mime'],
                        'size'                    => $file->getSize(),
                        'sha256'                  => hash_file('sha256', $file->getRealPath()),
                    ]);
                }

                return $message->refresh()->load('files');
            });
        } catch (Throwable $exception) {
            foreach ($stored as $entry) {
                try {
                    Storage::disk('media')->delete($entry['key']);
                } catch (Throwable $cleanup) {
                    report($cleanup);
                }
            }

            throw $exception;
        }

        return response()->json(['message' => $this->present($message)], 201);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $document = RepairRequest::resolveChatFor($request->user(), $id);
        $chat = RequestChat::query()->where('repair_request_id', $document->id)->firstOrFail();
        $validated = $request->validate(['lastMessageId' => ['required', 'integer', 'min:1']]);
        $messageId = (int) $validated['lastMessageId'];

        RequestChatMessage::query()
            ->where('request_chat_id', $chat->id)
            ->whereKey($messageId)
            ->firstOrFail();

        DB::statement(
            'INSERT INTO request_chat_reads '
                . '(request_chat_id, user_id, last_read_message_id, created_at, updated_at) '
                . 'VALUES (?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                . 'ON CONFLICT (request_chat_id, user_id) DO UPDATE '
                . 'SET last_read_message_id = GREATEST('
                . 'request_chat_reads.last_read_message_id, EXCLUDED.last_read_message_id), '
                . 'updated_at = CURRENT_TIMESTAMP',
            [$chat->id, $request->user()->id, $messageId],
        );

        return response()->json(['lastReadMessageId' => $messageId]);
    }

    public function download(Request $request, string $fileId): StreamedResponse
    {
        $file = RequestChatFile::query()
            ->where('uuid', $fileId)
            ->whereIn('request_chat_message_id', RequestChatMessage::query()
                ->select('id')
                ->whereIn('request_chat_id', RequestChat::query()
                    ->select('id')
                    ->whereIn('repair_request_id', RepairRequest::forChatUser($request->user())->select('id'))))
            ->firstOrFail();

        if (!Storage::disk($file->disk)->exists($file->object_key)) {
            throw new ApiException(404, 'NOT_FOUND', 'Файл чата не найден');
        }

        return Storage::disk($file->disk)->download(
            $file->object_key,
            $file->original_name,
            [
                'Content-Type'           => $file->mime_type,
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    private function present(RequestChatMessage $message): array
    {
        $mark = match ($message->sender_role) {
            UserRole::Customer => 'З',
            UserRole::Station => 'С',
            UserRole::Manager => 'У',
        };

        return [
            'id'        => $message->uuid,
            'sequence'  => $message->id,
            'text'      => $message->body,
            'createdAt' => $message->created_at->toISOString(),
            'sender'    => [
                'id'       => $message->sender_id,
                'name'     => $message->sender_name,
                'role'     => $message->sender_role->value,
                'roleMark' => $mark,
            ],
            'files' => $message->files->map(static fn (RequestChatFile $file): array => [
                'id'       => $file->uuid,
                'name'     => $file->original_name,
                'mimeType' => $file->mime_type,
                'size'     => (int) $file->size,
            ])->values(),
        ];
    }
}
