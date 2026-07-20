<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\HsnCodeController;
use App\Http\Controllers\UnitMasterController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\GeneralAccountController;
use App\Http\Controllers\LookupController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\OutstandingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\AuthController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Test routes available only in local/development environments.
if (app()->environment(['local', 'development'])) {
Route::get('/test', function () {
    return response()->json([
        'status' => true,
        'message' => 'API working perfectly 🎉'
    ]);
});

Route::get('/test-db-connection', function () {
    try {
        $count = \DB::table('categories')->count();
        return response()->json([
            'status' => true,
            'message' => 'Database connected',
            'categories_count' => $count
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ], 500);
    }
});

Route::post('/test-product-create', function (Illuminate\Http\Request $request) {
    try {
        // Log the incoming request
        \Log::info('Test product create request:', $request->all());
        
        // Test basic product creation with minimal data
        $product = \App\Models\Product::create([
            'name' => $request->input('name', 'Test Product'),
            'cat_id' => $request->input('cat_id', 65940),
            'brand' => $request->input('brand', 'Test Brand'),
            'description' => $request->input('description', 'Test Description'),
            'keywords' => $request->input('keywords', 'test'),
            'hsn_code' => $request->input('hsn_code', '123456'),
            'gst_percent' => $request->input('gst_percent', 18.0),
            'ctype_id' => 'vegetables_fruits',
            'seq_no' => 0,
            'start_date' => time(),
            'is_published' => 0,
            'is_used' => 1,
            'is_deleted' => 0,
            'in_stock' => 0,
            'inventory_type' => 'SINGLE',
            'inventory_unit_type' => 'WEIGHT',
            'spec_params' => '[]',
            'packs' => '[]',
            'default_pack_id' => '',
            'cache_txt' => '',
            'img_last_updated' => 0,
        ]);
        
        return response()->json([
            'status' => true,
            'message' => 'Test product created successfully',
            'data' => $product
        ], 201);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

Route::post('/simple-test', function (Illuminate\Http\Request $request) {
    return response()->json([
        'status' => true,
        'message' => 'Simple test working',
        'received_data' => $request->all()
    ]);
});

Route::get('/test-subcategory-simple/{parentId}', function ($parentId) {
    try {
        // Test creating a simple subcategory without image
        $data = [
            'name' => 'Simple Test Subcategory ' . time(),
            'parent_cat_id' => $parentId,
            'is_active' => 1,
            'type' => 0,
            'image_slug' => null,
            'image_name' => null,
            'img_last_updated' => 0,
        ];
        
        $subcategory = \App\Models\Category::create($data);
        
        return response()->json([
            'status' => true,
            'message' => 'Simple subcategory created successfully',
            'data' => $subcategory
        ], 201);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ], 500);
    }
});

Route::post('/create-category-simple', function (Illuminate\Http\Request $request) {
    try {
        // Just create a basic category without image first
        $category = \App\Models\Category::create([
            'name' => $request->input('name', 'Test Category'),
            'parent_cat_id' => 0,
            'is_active' => 1,
            'type' => 0,
            'image_slug' => null,
            'image_name' => null,
            'img_last_updated' => 0,
        ]);
        
        return response()->json([
            'status' => true,
            'message' => 'Category created successfully',
            'data' => $category
        ], 201);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ], 500);
    }
});

Route::post('/test-category-create', function (Illuminate\Http\Request $request) {
    // Set error reporting
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    
    try {
        $name = $request->input('name', 'Test Category');
        $isActive = (int)$request->input('is_active', 1);
        $imageBase64 = $request->input('imageBase64');
        
        // Create basic category data with ALL required fields
        $categoryData = [
            'name' => $name,
            'parent_cat_id' => 0,  // CRITICAL: This was missing in some attempts
            'is_active' => $isActive,
            'type' => 0,
            'image_slug' => null,
            'image_name' => null,
            'img_last_updated' => 0,
        ];
        
        // Handle image if provided
        if (!empty($imageBase64)) {
            try {
                // Remove data URL prefix
                if (strpos($imageBase64, ',') !== false) {
                    $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
                }
                
                // Decode and save
                $imageData = base64_decode($imageBase64);
                if ($imageData && strlen($imageData) > 50) {
                    // Use VERY short filename to fit varchar(15) constraint
                    $fileName = 'c' . substr(time(), -6) . '.png';  // c123456.png = 11 chars
                    $uploadPath = storage_path('app/uploads/categories');
                    
                    // Create directory if needed
                    if (!is_dir($uploadPath)) {
                        mkdir($uploadPath, 0755, true);
                    }
                    
                    // Save file
                    if (file_put_contents($uploadPath . '/' . $fileName, $imageData)) {
                        $categoryData['image_name'] = $fileName;
                        $categoryData['image_slug'] = $fileName;  // Same short name
                        $categoryData['img_last_updated'] = time();
                    }
                }
            } catch (\Exception $imageError) {
                // Continue without image if there's an error
                // Don't fail the whole request
            }
        }
        
        // Create category
        $category = \App\Models\Category::create($categoryData);
        
        return response()->json([
            'status' => true,
            'message' => 'Category created successfully',
            'data' => $category
        ], 201);
        
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ], 500);
    }
});

Route::post('/test-controller-direct', function (Illuminate\Http\Request $request) {
    try {
        $controller = new \App\Http\Controllers\CategoryController();
        return $controller->store($request);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Controller error: ' . $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
});

Route::get('/db-test', function () {
    try {
        \DB::connection()->getPdo();
        return response()->json([
            'status' => true,
            'message' => 'Database connected successfully ✅'
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Database connection failed: ' . $e->getMessage()
        ], 500);
    }
});
}

Route::get('/all-subcategories', function () {
    try {
        // Get limit parameter (default 200, max 1000)
        $limit = (int)request()->get('limit', 200);
        $limit = min(max($limit, 1), 1000); // Ensure limit is between 1 and 1000
        
        $offset = (int)request()->get('offset', 0);
        $offset = max($offset, 0); // Ensure offset is not negative
        
        // Optimized query to get subcategories with parent category names
        $subcategories = \DB::select('
            SELECT 
                s.*,
                COALESCE(p.name, "Unknown") as parent_category_name
            FROM categories s
            LEFT JOIN categories p ON s.parent_cat_id = p.cat_id
            WHERE s.parent_cat_id != 0 AND s.parent_cat_id IS NOT NULL
            ORDER BY p.name, s.name
            LIMIT ' . $limit . ' OFFSET ' . $offset
        );
        
        // Get total count for pagination info
        $totalCount = \DB::select('
            SELECT COUNT(*) as total
            FROM categories s
            WHERE s.parent_cat_id != 0 AND s.parent_cat_id IS NOT NULL
        ')[0]->total;
        
        // Convert to proper format with null safety
        $result = [];
        foreach ($subcategories as $subcategory) {
            $result[] = [
                'cat_id' => (int)$subcategory->cat_id,
                'name' => $subcategory->name ?? '',
                'parent_cat_id' => (int)$subcategory->parent_cat_id,
                'parent_category_name' => $subcategory->parent_category_name ?? 'Unknown',
                'is_active' => (bool)$subcategory->is_active,
                'type' => (int)$subcategory->type,
                'image_slug' => $subcategory->image_slug ?? null,
                'image_name' => $subcategory->image_name ?? null,
                'img_last_updated' => (int)($subcategory->img_last_updated ?? 0),
            ];
        }
        
        return response()->json([
            'status' => true,
            'data' => $result,
            'pagination' => [
                'count' => count($result),
                'total' => (int)$totalCount,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => 'Error loading subcategories: ' . $e->getMessage()
        ], 500);
    }
});

if (app()->environment(['local', 'development'])) {
Route::get('/debug-categories', function () {
    try {
        // Test database connection first
        $connection = \DB::connection();
        $pdo = $connection->getPdo();
        
        // Simple raw query
        $categories = \DB::select('SELECT * FROM categories WHERE parent_cat_id = 0 LIMIT 5');
        
        return response()->json([
            'status' => true,
            'message' => 'Direct SQL query',
            'count' => count($categories),
            'data' => $categories
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage(),
            'line' => $e->getLine(),
            'file' => basename($e->getFile())
        ], 500);
    }
});

Route::get('/test-categories-debug', function () {
    try {
        // Direct database query
        $categories = \DB::table('categories')
            ->where('parent_cat_id', 0)
            ->get();
        
        return response()->json([
            'status' => true,
            'message' => 'Direct DB query',
            'count' => $categories->count(),
            'data' => $categories->take(5)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});

Route::get('/test-categories-model', function () {
    try {
        // Using Category model
        $categories = \App\Models\Category::where('parent_cat_id', 0)->get();
        
        return response()->json([
            'status' => true,
            'message' => 'Category model query',
            'count' => $categories->count(),
            'data' => $categories->take(5)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});

// Check tables
Route::get('/check-tables', function () {
    try {
        $tables = \DB::select('SHOW TABLES');
        return response()->json([
            'status' => true,
            'tables' => $tables
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});

// Check products table structure
Route::get('/check-products-table', function () {
    try {
        $structure = \DB::select('DESCRIBE product'); // Fixed: table name is singular
        return response()->json([
            'status' => true,
            'structure' => $structure
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});

// Check categories table structure
Route::get('/check-categories-table', function () {
    try {
        $structure = \DB::select('DESCRIBE categories');
        return response()->json([
            'status' => true,
            'structure' => $structure
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});

// Check if categories table has data
Route::get('/check-categories-data', function () {
    try {
        $count = \DB::table('categories')->count();
        $sample = \DB::table('categories')->limit(5)->get();
        return response()->json([
            'status' => true,
            'total_count' => $count,
            'sample_data' => $sample
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => false,
            'message' => $e->getMessage()
        ], 500);
    }
});
}

// Category Routes
Route::prefix('categories')->middleware(['throttle:240,1'])->group(function () {
    if (app()->environment(['local', 'development'])) {
        Route::post('/test-store', [CategoryController::class, 'testStore']);
    }
    Route::get('/all-records', [CategoryController::class, 'allRecords']);
    Route::get('/hierarchy', [CategoryController::class, 'hierarchy']);
    Route::get('/with-counts', function () {
        $limit = min(max((int)request()->get('limit', 30), 1), 100);
        $offset = max((int)request()->get('offset', 0), 0);
        $search = trim(request()->get('search', ''));

        $whereClause = 'c.parent_cat_id = 0';
        $params = [];
        if ($search !== '') {
            $searchLower = mb_strtolower($search);
            if (is_numeric(trim($search))) {
                $whereClause .= ' AND (LOWER(c.name) LIKE ? OR c.cat_id = ?)';
                $params[] = '%' . $searchLower . '%';
                $params[] = (int)$search;
            } else {
                $whereClause .= ' AND LOWER(c.name) LIKE ?';
                $params[] = '%' . $searchLower . '%';
            }
        }

        $countSql = "SELECT COUNT(*) as total FROM categories c WHERE {$whereClause}";
        $totalCount = (int)(\DB::select($countSql, $params)[0]->total ?? 0);

        $sql = "
            SELECT 
                c.*,
                COALESCE(sc.subcategory_count, 0) as subcategories_count
            FROM categories c
            LEFT JOIN (
                SELECT parent_cat_id, COUNT(*) as subcategory_count 
                FROM categories 
                WHERE parent_cat_id != 0 AND parent_cat_id IS NOT NULL
                GROUP BY parent_cat_id
            ) sc ON c.cat_id = sc.parent_cat_id
            WHERE {$whereClause}
            ORDER BY c.name
            LIMIT {$limit} OFFSET {$offset}
        ";
        $categories = \DB::select($sql, $params);

        $result = [];
        foreach ($categories as $category) {
            $result[] = [
                'cat_id' => (int)$category->cat_id,
                'name' => $category->name ?? '',
                'parent_cat_id' => (int)$category->parent_cat_id,
                'is_active' => (bool)$category->is_active,
                'type' => (int)$category->type,
                'image_slug' => $category->image_slug ?? null,
                'image_name' => $category->image_name ?? null,
                'img_last_updated' => (int)($category->img_last_updated ?? 0),
                'subcategories_count' => (int)$category->subcategories_count,
                'products_count' => 0
            ];
        }

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
    });
    if (app()->environment(['local', 'development'])) {
        Route::get('/test-with-counts', [CategoryController::class, 'testWithCounts']);
    }
    Route::get('/active', [CategoryController::class, 'active']);
    Route::get('/', [CategoryController::class, 'index']);
    Route::get('/{id}', [CategoryController::class, 'show']);
    Route::get('/{id}/subcategories', [CategoryController::class, 'subcategories']);
    Route::get('/{id}/subcategories/active', [CategoryController::class, 'activeSubcategories']);
    Route::middleware(['api.key', 'throttle:60,1'])->group(function () {
        Route::post('/', [CategoryController::class, 'store']);
        Route::post('/{id}/subcategories', [CategoryController::class, 'storeSubcategory']);
        Route::put('/{id}', [CategoryController::class, 'update']);
        Route::patch('/{id}/toggle-status', [CategoryController::class, 'toggleStatus']);
        Route::delete('/{id}', [CategoryController::class, 'destroy']);
    });
});

// User & Supplier management routes
Route::prefix('users')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/', [UserController::class, 'index']);
    Route::get('/{id}', [UserController::class, 'show'])->whereNumber('id');
    Route::post('/', [UserController::class, 'store']);
    Route::put('/{id}', [UserController::class, 'update'])->whereNumber('id');
    Route::get('/contact/{phone}', [UserController::class, 'checkContact']);
});

Route::prefix('suppliers')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/', [SupplierController::class, 'index']);
    Route::get('/{id}', [SupplierController::class, 'show'])->whereNumber('id');
    Route::post('/', [SupplierController::class, 'store']);
    Route::put('/{id}', [SupplierController::class, 'update'])->whereNumber('id');
    Route::get('/contact/{phone}', [SupplierController::class, 'checkPhone']);
});

Route::prefix('general-accounts')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/', [GeneralAccountController::class, 'index']);
    Route::get('/check-account-no/{accountNo}', [GeneralAccountController::class, 'checkAccountNo'])->whereNumber('accountNo');
    Route::get('/{id}', [GeneralAccountController::class, 'show'])->whereNumber('id');
    Route::post('/', [GeneralAccountController::class, 'store']);
    Route::put('/{id}', [GeneralAccountController::class, 'update'])->whereNumber('id');
});

// Accounting vouchers (CP / BP / CR / BR) with bill-wise accounting
Route::prefix('vouchers')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/next-no', [VoucherController::class, 'nextNo']);
    Route::get('/adjacent', [VoucherController::class, 'adjacent']);
    Route::get('/find', [VoucherController::class, 'find']);
    Route::get('/', [VoucherController::class, 'index']);
    Route::get('/{id}', [VoucherController::class, 'show'])->whereNumber('id');
    Route::post('/', [VoucherController::class, 'store']);
    Route::put('/{id}', [VoucherController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [VoucherController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/pdc/clear', [VoucherController::class, 'clearPdc'])->whereNumber('id');
    Route::post('/{id}/pdc/bounce', [VoucherController::class, 'bouncePdc'])->whereNumber('id');
});

// Bill-wise outstanding picker (used during voucher entry)
Route::prefix('outstanding')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/bills', [OutstandingController::class, 'bills']);
});

// Accounting reports
Route::prefix('reports')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/general-ledger', [ReportController::class, 'generalLedger']);
    Route::get('/customer-ledger', [ReportController::class, 'customerLedger']);
    Route::get('/supplier-ledger', [ReportController::class, 'supplierLedger']);
    Route::get('/outstanding', [ReportController::class, 'outstanding']);
    Route::get('/outstanding-detail', [ReportController::class, 'outstandingDetail']);
    Route::get('/ledger-detail', [ReportController::class, 'ledgerDetail']);
    Route::get('/pdc-outstanding', [ReportController::class, 'pdcOutstanding']);
});

Route::get('/business-types', [LookupController::class, 'businessTypes']);
Route::get('/departments', [LookupController::class, 'departments']);
Route::get('/pincodes/{pincode}', [LookupController::class, 'pincode']);

Route::prefix('subcategories')->middleware(['api.key', 'throttle:60,1'])->group(function () {
    Route::put('/{id}', [CategoryController::class, 'update']);
    Route::delete('/{id}', [CategoryController::class, 'destroySubcategory']);
});

// Product Routes
Route::prefix('products')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/', [ProductController::class, 'index']);
    Route::get('/sales-report', [ProductController::class, 'salesReport']);
    Route::get('/{id}', [ProductController::class, 'show']);
    Route::middleware(['api.key', 'throttle:60,1'])->group(function () {
        Route::post('/basic', [ProductController::class, 'storeBasic']);
        Route::put('/{id}/details', [ProductController::class, 'updateDetails']);
        Route::put('/{id}/packages', [ProductController::class, 'updatePackages']);
        Route::post('/{id}/photos', [ProductController::class, 'addPhoto']);
        Route::delete('/photos/{photoId}', [ProductController::class, 'deletePhoto']);
        Route::patch('/{id}/toggle-status', [ProductController::class, 'toggleStatus']);
        Route::delete('/{id}', [ProductController::class, 'destroy']);
    });
});

// HSN Code Routes
Route::prefix('hsn-codes')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/search', [HsnCodeController::class, 'search']);
    Route::get('/', [HsnCodeController::class, 'index']);
    Route::get('/{id}', [HsnCodeController::class, 'show']);
    Route::middleware(['api.key', 'throttle:60,1'])->group(function () {
        Route::post('/', [HsnCodeController::class, 'store']);
        Route::post('/{id}/toggle-active', [HsnCodeController::class, 'toggleActive']);
        Route::put('/{id}', [HsnCodeController::class, 'update']);
        Route::delete('/{id}', [HsnCodeController::class, 'destroy']);
    });
});

// Unit Master Routes
Route::prefix('units-master')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/search', [UnitMasterController::class, 'search']);
    Route::get('/', [UnitMasterController::class, 'index']);
    Route::get('/{id}', [UnitMasterController::class, 'show']);
    Route::middleware(['api.key', 'throttle:60,1'])->group(function () {
        Route::post('/', [UnitMasterController::class, 'store']);
        Route::put('/{id}', [UnitMasterController::class, 'update']);
        Route::delete('/{id}', [UnitMasterController::class, 'destroy']);
    });
});

// Tax Routes
Route::prefix('taxes')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/search', [TaxController::class, 'search']);
    Route::get('/', [TaxController::class, 'index']);
    Route::get('/{id}', [TaxController::class, 'show']);
    Route::middleware(['api.key', 'throttle:60,1'])->group(function () {
        Route::post('/', [TaxController::class, 'store']);
        Route::post('/{id}/toggle-active', [TaxController::class, 'toggleActive']);
        Route::put('/{id}', [TaxController::class, 'update']);
        Route::delete('/{id}', [TaxController::class, 'destroy']);
    });
});

// Order Routes
Route::prefix('orders')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/', [OrderController::class, 'index']);
    Route::get('/health/missing-items', [OrderController::class, 'missingItemsHealth']);
    Route::get('/customer/{buyerUserId}/history', [OrderController::class, 'customerOrderHistory']);
    Route::get('/customer/{buyerUserId}/product-history', [OrderController::class, 'customerProductHistory']);
    Route::get('/customer/{buyerUserId}/invoices', [OrderController::class, 'customerInvoices']);
    Route::get('/customer/{buyerUserId}/returns', [OrderController::class, 'customerReturns']);
    Route::get('/{id}', [OrderController::class, 'show']);
});

// Purchase Routes (supplier-side Order / Invoice / Return, read-only)
Route::prefix('purchases')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/orders', [PurchaseController::class, 'orders']);
    Route::get('/invoices', [PurchaseController::class, 'invoices']);
    Route::get('/returns', [PurchaseController::class, 'returns']);
});

// Auth Routes (staff login via deli_staff table)
Route::prefix('auth')->middleware(['throttle:20,1'])->group(function () {
    Route::post('/send-otp', [AuthController::class, 'sendOtp']);
    Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
});

// Note Routes (Notepad Module)
Route::prefix('notes')->middleware(['throttle:240,1'])->group(function () {
    Route::get('/', [NoteController::class, 'index']);
    Route::get('/{id}', [NoteController::class, 'show']);
    Route::post('/', [NoteController::class, 'store']);
    Route::put('/{id}', [NoteController::class, 'update']);
    Route::delete('/{id}', [NoteController::class, 'destroy']);
    Route::delete('/folder/{folderName}', [NoteController::class, 'deleteFolder']);
});
