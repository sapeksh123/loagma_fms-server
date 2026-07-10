<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class CategoryController extends Controller
{
    // GET /api/categories - supports limit, offset, search for performance
    public function index(Request $request)
    {
        try {
            $limit = min(max((int)$request->get('limit', 25), 1), 100);
            $offset = max((int)$request->get('offset', 0), 0);
            $search = trim($request->get('search', ''));

            $query = Category::where(function ($q) {
                $q->where('parent_cat_id', 0)->orWhereNull('parent_cat_id');
            });

            if ($search !== '') {
                $searchLower = mb_strtolower($search);
                $query->where(function ($q) use ($searchLower, $search) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%' . $searchLower . '%']);
                    if (is_numeric(trim($search))) {
                        $q->orWhere('cat_id', (int)$search);
                    }
                });
            }

            $totalCount = $query->count();
            $categories = $query->orderBy('name')->offset($offset)->limit($limit)->get();

            return response()->json([
                'status' => true,
                'data' => $categories,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + $categories->count()) < $totalCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/categories/active
    public function active()
    {
        try {
            $categories = Category::where(function($query) {
                    $query->where('parent_cat_id', 0)
                          ->orWhereNull('parent_cat_id');
                })
                ->where('is_active', true)
                ->get();
            
            return response()->json([
                'status' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/categories/{id}
    public function show($id)
    {
        try {
            $category = Category::findOrFail($id);
            
            return response()->json([
                'status' => true,
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    // GET /api/categories/{id}/subcategories - supports limit, offset, search
    public function subcategories(Request $request, $id)
    {
        try {
            $limit = min(max((int)$request->get('limit', 25), 1), 100);
            $offset = max((int)$request->get('offset', 0), 0);
            $search = trim($request->get('search', ''));

            $query = Category::where('parent_cat_id', (int)$id);
            if ($search !== '') {
                $searchLower = mb_strtolower($search);
                $query->where(function ($q) use ($searchLower, $search) {
                    $q->whereRaw('LOWER(name) LIKE ?', ['%' . $searchLower . '%']);
                    if (is_numeric(trim($search))) {
                        $q->orWhere('cat_id', (int)$search);
                    }
                });
            }

            $totalCount = $query->count();
            $subcategories = $query->orderBy('name')->offset($offset)->limit($limit)->get();

            $result = $subcategories->map(function ($subcategory) {
                return [
                    'cat_id' => $subcategory->cat_id,
                    'name' => $subcategory->name,
                    'parent_cat_id' => $subcategory->parent_cat_id,
                    'is_active' => (bool)$subcategory->is_active,
                    'type' => $subcategory->type,
                    'image_slug' => $subcategory->image_slug,
                    'image_name' => $subcategory->image_name,
                    'img_last_updated' => $subcategory->img_last_updated,
                ];
            })->values()->all();

            return response()->json([
                'status' => true,
                'data' => $result,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => ($offset + count($result)) < $totalCount
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error loading subcategories: ' . $e->getMessage()
            ], 500);
        }
    }

    // GET /api/categories/{id}/subcategories/active
    public function activeSubcategories($id)
    {
        try {
            // Use direct SQL for better performance
            $subcategories = \DB::select('
                SELECT * FROM categories 
                WHERE parent_cat_id = ? AND is_active = 1 
                ORDER BY name
            ', [$id]);
            
            // Convert to proper format
            $result = [];
            foreach ($subcategories as $subcategory) {
                $result[] = [
                    'cat_id' => $subcategory->cat_id,
                    'name' => $subcategory->name,
                    'parent_cat_id' => $subcategory->parent_cat_id,
                    'is_active' => (bool)$subcategory->is_active,
                    'type' => $subcategory->type,
                    'image_slug' => $subcategory->image_slug,
                    'image_name' => $subcategory->image_name,
                    'img_last_updated' => $subcategory->img_last_updated,
                ];
            }
            
            return response()->json([
                'status' => true,
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error loading active subcategories: ' . $e->getMessage()
            ], 500);
        }
    }

    // GET /api/categories/hierarchy
    public function hierarchy()
    {
        try {
            $categories = Category::with('subcategories')
                ->where('parent_cat_id', 0)
                ->orWhereNull('parent_cat_id')
                ->get();
            
            return response()->json([
                'status' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/categories/with-counts
    public function withCounts()
    {
        try {
            $categories = Category::withCount(['subcategories', 'products'])
                ->whereNull('parent_cat_id')
                ->get();
            
            return response()->json([
                'status' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/categories/all-records
    public function allRecords()
    {
        try {
            $categories = Category::all();
            
            return response()->json([
                'status' => true,
                'data' => $categories
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/categories
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'is_active' => 'boolean',
            'type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $data = $validator->validated();
            
            if ($request->hasFile('image')) {
                $file = $request->file('image');
                $data['image_name'] = $file->store('categories', 'public');
                $data['image_slug'] = $file->getClientOriginalName();
            }
            
            unset($data['image']); // Remove image field, we use image_name and image_slug

            $data['cat_id'] = (int) (Category::max('cat_id') ?? 0) + 1;
            $data['parent_cat_id'] = $data['parent_cat_id'] ?? 0;

            $category = Category::create($data);
            
            return response()->json([
                'status' => true,
                'message' => 'Category created successfully',
                'data' => $category
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/categories/{id}/subcategories
    public function storeSubcategory(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'image_base64' => 'nullable|string',
            'is_active' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $parent = Category::findOrFail($id);
            $data = [
                'name' => $request->name,
                'parent_cat_id' => (int)$id,
                'is_active' => $request->has('is_active') ? (int)(bool)$request->is_active : 1,
                'type' => 1,
            ];

            $uploadPath = public_path('uploads/categories');
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Handle base64 image (from Flutter)
            // image_slug column is varchar(15) - use short filename
            if ($request->has('image_base64') && !empty(trim($request->image_base64))) {
                $imageBase64 = $request->image_base64;
                if (strpos($imageBase64, ',') !== false) {
                    $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
                }
                $imageData = base64_decode($imageBase64);
                if ($imageData && strlen($imageData) > 50) {
                    $fileName = 's' . substr((string)time(), -5) . mt_rand(10, 99) . '.png'; // max 12 chars
                    if (file_put_contents($uploadPath . '/' . $fileName, $imageData)) {
                        $data['image_name'] = $fileName;
                        $data['image_slug'] = $fileName;
                        $data['img_last_updated'] = time();
                    }
                }
            } elseif ($request->hasFile('image')) {
                $file = $request->file('image');
                $fileName = 's' . substr((string)time(), -5) . mt_rand(10, 99) . '.png'; // max 12 chars
                if ($file->move($uploadPath, $fileName)) {
                    $data['image_name'] = $fileName;
                    $data['image_slug'] = $fileName;
                    $data['img_last_updated'] = time();
                }
            }

            $data['cat_id'] = (int) (Category::max('cat_id') ?? 0) + 1;

            $subcategory = Category::create($data);

            return response()->json([
                'status' => true,
                'message' => 'Subcategory created successfully',
                'data' => $subcategory
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 404);
        }
    }

    // PUT /api/categories/{id}
    public function update(Request $request, $id)
    {
        try {
            $category = Category::findOrFail($id);
            
            // Handle different update scenarios
            $data = [];
            
            // Update name if provided
            if ($request->has('name')) {
                $data['name'] = $request->input('name');
            }
            
            // Update active status if provided
            if ($request->has('is_active')) {
                $data['is_active'] = (int)$request->input('is_active');
            }
            
            // Handle image update (base64 or file upload)
            if ($request->has('image_base64') && !empty($request->input('image_base64'))) {
                // Handle base64 image (from Flutter)
                $imageBase64 = $request->input('image_base64');
                
                // Remove data URL prefix if present
                if (strpos($imageBase64, ',') !== false) {
                    $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
                }
                
                $imageData = base64_decode($imageBase64);
                
                if ($imageData && strlen($imageData) > 50) {
                    // Generate short filename to fit varchar(15) constraint
                    $fileName = 's' . substr(time(), -6) . '.png'; // s123456.png for subcategory
                    $uploadPath = public_path('uploads/categories');
                    
                    // Create directory if needed
                    if (!is_dir($uploadPath)) {
                        mkdir($uploadPath, 0755, true);
                    }
                    
                    // Delete old image if exists
                    if ($category->image_name) {
                        $oldPath = $uploadPath . '/' . $category->image_name;
                        if (file_exists($oldPath)) {
                            unlink($oldPath);
                        }
                    }
                    
                    // Save new image
                    if (file_put_contents($uploadPath . '/' . $fileName, $imageData)) {
                        $data['image_name'] = $fileName;
                        $data['image_slug'] = $fileName;
                        $data['img_last_updated'] = time();
                    }
                }
            } elseif ($request->hasFile('image')) {
                // Handle file upload (traditional form upload)
                $file = $request->file('image');
                $fileName = 's' . substr(time(), -6) . '.png';
                $uploadPath = public_path('uploads/categories');
                
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath, 0755, true);
                }
                
                // Delete old image if exists
                if ($category->image_name) {
                    $oldPath = $uploadPath . '/' . $category->image_name;
                    if (file_exists($oldPath)) {
                        unlink($oldPath);
                    }
                }
                
                // Save new image
                if ($file->move($uploadPath, $fileName)) {
                    $data['image_name'] = $fileName;
                    $data['image_slug'] = $fileName;
                    $data['img_last_updated'] = time();
                }
            }
            
            // Update the category/subcategory
            if (!empty($data)) {
                $category->update($data);
            }
            
            return response()->json([
                'status' => true,
                'message' => $category->parent_cat_id == 0 ? 'Category updated successfully' : 'Subcategory updated successfully',
                'data' => $category->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Update failed: ' . $e->getMessage()
            ], 500);
        }
    }

    // PATCH /api/categories/{id}/toggle-status
    public function toggleStatus($id)
    {
        try {
            $category = Category::findOrFail($id);
            $category->is_active = !$category->is_active;
            $category->save();
            
            return response()->json([
                'status' => true,
                'message' => 'Category status toggled successfully',
                'data' => $category
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    // DELETE /api/categories/{id}
    public function destroy($id)
    {
        try {
            $category = Category::findOrFail($id);
            
            // Check if category has subcategories
            if ($category->subcategories()->count() > 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'Cannot delete category with existing subcategories'
                ], 409);
            }
            
            // Delete image if exists
            if ($category->image) {
                Storage::disk('public')->delete($category->image);
            }
            
            $category->delete();
            
            return response()->json([
                'status' => true,
                'message' => 'Category deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Category not found'
            ], 404);
        }
    }

    // DELETE /api/subcategories/{id}
    public function destroySubcategory($id)
    {
        try {
            $subcategory = Category::whereNotNull('parent_cat_id')
                ->findOrFail($id);
            
            // Delete image if exists
            if ($subcategory->image) {
                Storage::disk('public')->delete($subcategory->image);
            }
            
            $subcategory->delete();
            
            return response()->json([
                'status' => true,
                'message' => 'Subcategory deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Subcategory not found'
            ], 404);
        }
    }
}
