<?php

namespace App\Http\Controllers;

use App\Models\Note;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class NoteController extends Controller
{
    /**
     * Get all notes for user (optionally filtered by folder)
     * GET /api/notes?user_id=1&folder_name=Work
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->query('user_id');
            $folderName = $request->query('folder_name');
            $page = max((int) $request->get('page', 1), 1);
            $perPage = min(max((int) $request->get('per_page', 50), 1), 500);

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required',
                ], 400);
            }

            $query = Note::where('user_id', $userId);

            if ($folderName) {
                $query->where('folder_name', $folderName);
            }

            $total = (clone $query)->count();
            $data = $query->orderBy('updatedAt', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get()
                ->map(fn (Note $note) => $this->formatNote($note))
                ->values();

            return response()->json([
                'status' => true,
                'data' => $data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => (int) max(1, ceil($total / $perPage)),
                    'has_more' => ($page * $perPage) < $total,
                ],
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific note
     * GET /api/notes/{id}
     */
    public function show($id)
    {
        try {
            $note = Note::find($id);

            if (!$note) {
                return response()->json([
                    'status' => false,
                    'message' => 'Note not found',
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $this->formatNote($note),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a new note
     * POST /api/notes
     */
    public function store(Request $request)
    {
        try {
            $validated = $this->validateNote($request);

            $note = Note::create([
                'id' => Str::uuid(),
                'user_id' => $validated['user_id'],
                'folder_name' => $validated['folder_name'],
                'title' => $validated['title'],
                'content' => $validated['content'] ?? '',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Note created successfully',
                'data' => $this->formatNote($note),
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a note
     * PUT /api/notes/{id}
     */
    public function update(Request $request, $id)
    {
        try {
            $note = Note::find($id);

            if (!$note) {
                return response()->json([
                    'status' => false,
                    'message' => 'Note not found',
                ], 404);
            }

            $validated = $this->validateNote($request, $id);
            $note->fill($validated);
            $note->save();

            return response()->json([
                'status' => true,
                'message' => 'Note updated successfully',
                'data' => $this->formatNote($note->fresh()),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a note
     * DELETE /api/notes/{id}
     */
    public function destroy($id)
    {
        try {
            $note = Note::find($id);

            if (!$note) {
                return response()->json([
                    'status' => false,
                    'message' => 'Note not found',
                ], 404);
            }

            $note->delete();

            return response()->json([
                'status' => true,
                'message' => 'Note deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete all notes in a folder
     * DELETE /api/notes/folder/{folderName}?user_id=1
     */
    public function deleteFolder(Request $request, $folderName)
    {
        try {
            $userId = $request->query('user_id');

            if (!$userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'user_id is required',
                ], 400);
            }

            $deletedCount = Note::where('user_id', $userId)
                ->where('folder_name', $folderName)
                ->delete();

            return response()->json([
                'status' => true,
                'message' => 'Folder and all notes deleted successfully',
                'deleted_count' => $deletedCount,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate note data
     */
    private function validateNote(Request $request, ?string $ignoreId = null): array
    {
        $validator = Validator::make($request->all(), [
            'user_id' => [
                'required',
                'integer',
            ],
            'folder_name' => [
                'required',
                'string',
                'max:255',
            ],
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'content' => [
                'nullable',
                'string',
            ],
        ], [
            'user_id.required' => 'User ID is required',
            'folder_name.required' => 'Folder name is required',
            'title.required' => 'Title is required',
        ]);

        return $validator->validate();
    }

    /**
     * Format note for response
     */
    private function formatNote(Note $note): array
    {
        return [
            'id' => $note->id,
            'user_id' => $note->user_id,
            'folder_name' => $note->folder_name,
            'title' => $note->title,
            'content' => $note->content,
            'createdAt' => $note->createdAt?->toDateTimeString(),
            'updatedAt' => $note->updatedAt?->toDateTimeString(),
        ];
    }
}
