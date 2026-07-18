<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ProductController extends Controller
{
    private array $schemaCache = [];

    // GET /api/products/sales-report
    public function salesReport(Request $request)
    {

        try {
            $search = trim((string) $request->get('search', ''));
            $page = max((int) $request->get('page', 1), 1);
            $perPage = min(max((int) $request->get('per_page', 20), 1), 50);
            $status = strtolower(trim((string) $request->get('status', 'all')));
            $dayFilter = strtolower(trim((string) $request->get('day_filter', '')));
            $dateFilter = strtolower(trim((string) $request->get('date_filter', $dayFilter === '' ? 'all' : $dayFilter)));

            $fromDate = trim((string) $request->get('from_date', ''));
            $toDate = trim((string) $request->get('to_date', ''));
            $fromTsParam = trim((string) $request->get('from_ts', ''));
            $toTsParam = trim((string) $request->get('to_ts', ''));

            $fromTs = null;
            $toTs = null;

            if ($dateFilter !== '' && $dateFilter !== 'all') {
                $predefinedRange = $this->resolveDateRange($dateFilter);
                if ($predefinedRange !== null) {
                    $fromTs = $predefinedRange['from'];
                    $toTs = $predefinedRange['to'];
                }
            }

            if ($fromTsParam !== '' && ctype_digit($fromTsParam)) {
                $fromTs = (int) $fromTsParam;
            }

            if ($toTsParam !== '' && ctype_digit($toTsParam)) {
                $toTs = (int) $toTsParam;
            }

            if ($fromTs === null && $fromDate !== '') {
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
                    try {
                        $fromTs = Carbon::createFromFormat($format, $fromDate)->startOfDay()->timestamp;
                        break;
                    } catch (\Exception $e) {
                        // Try next format.
                    }
                }

                if ($fromTs === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid from_date format. Expected Y-m-d, d/m/Y or d-m-Y.',
                    ], 422);
                }
            }

            if ($toTs === null && $toDate !== '') {
                foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
                    try {
                        $toTs = Carbon::createFromFormat($format, $toDate)->endOfDay()->timestamp;
                        break;
                    } catch (\Exception $e) {
                        // Try next format.
                    }
                }

                if ($toTs === null) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid to_date format. Expected Y-m-d, d/m/Y or d-m-Y.',
                    ], 422);
                }
            }


            $cacheKey = 'sales_report:v4:' . md5(json_encode([
                'search' => $search,
                'page' => $page,
                'perPage' => $perPage,
                'status' => $status,
                'dateFilter' => $dateFilter,
                'fromTs' => $fromTs,
                'toTs' => $toTs,
            ]));

            $responsePayload = Cache::remember($cacheKey, now()->addSeconds(45), function () use (
                $search,
                $page,
                $perPage,
                $status,
                $fromTs,
                $toTs
            ) {
                // Build the base query with all filters/search applied first
                $baseQuery = DB::table('product as p')
                    ->where('p.is_deleted', 0);

                if ($search !== '') {
                    $baseQuery->where(function ($q) use ($search) {
                        if (is_numeric($search)) {
                            $q->orWhere('p.product_id', (int) $search);
                        }
                        $like = '%' . $search . '%';
                        $q->orWhere('p.name',     'LIKE', $like)
                          ->orWhere('p.keywords', 'LIKE', $like)
                          ->orWhere('p.brand',    'LIKE', $like);
                    });
                }

                // Add more filters here as needed (status, date, etc.)
                // Example: $baseQuery->where('p.status', $status);

                // If date filters are present, join with sales/order tables as needed
                // ...existing code for date filter joins...

                // Group and aggregate as needed
                $reportGroupedQuery = (clone $baseQuery)
                    ->select(
                        'p.product_id',
                        'p.name as product_name',
                        'p.display_photo as product_image',
                        'p.packs as product_packs',
                        'p.keywords as product_keywords',
                        'p.brand as product_brand'
                    )
                    ->groupBy('p.product_id', 'p.name', 'p.display_photo', 'p.packs', 'p.keywords', 'p.brand');

                $totalProducts = (clone $reportGroupedQuery)
                    ->distinct('p.product_id')
                    ->count('p.product_id');

                $lastPage = max((int) ceil($totalProducts / $perPage), 1);
                $offset = ($page - 1) * $perPage;

                // Only apply limit/offset after all filters/search
                $rows = (clone $reportGroupedQuery)
                    ->orderBy('p.name')
                    ->offset($offset)
                    ->limit($perPage)
                    ->get();

                $productIds = $rows
                    ->pluck('product_id')
                    ->map(fn($id) => (int) $id)
                    ->filter(fn($id) => $id > 0)
                    ->values()
                    ->all();

                $packageStats = [];
                if (!empty($productIds)) {
                    try {
                        // Load package stats for visible products even in "All Time"
                        // so package chips and order IDs are always available in UI.
                        $packageStats = $this->getPackageStatsForProducts($productIds, $status, $fromTs, $toTs);
                    } catch (\Throwable $e) {
                        $packageStats = [];
                    }
                }

                $data = $rows->map(function ($row) use ($packageStats) {
                    $productId = (int) ($row->product_id ?? 0);
                    return [
                        'product_id'       => $productId,
                        'product_name'     => (string) ($row->product_name     ?? ''),
                        'product_image'    => (string) ($row->product_image    ?? ''),
                        'product_keywords' => (string) ($row->product_keywords ?? ''),
                        'product_brand'    => (string) ($row->product_brand    ?? ''),
                        'total_orders'     => (int)    ($row->total_orders     ?? 0),
                        'total_quantity'   => (int)    ($row->total_quantity   ?? 0),
                        'product_packages' => $this->decodeProductPackages($row->product_packs ?? null),
                        'packages'         => $packageStats[$productId] ?? [],
                    ];
                })->values();

                return [
                    'success' => true,
                    'data' => $data,
                    'pagination' => [
                        'total' => $totalProducts,
                        'per_page' => $perPage,
                        'current_page' => $page,
                        'last_page' => $lastPage,
                        'from' => $totalProducts > 0 ? $offset + 1 : 0,
                        'to' => min($offset + $data->count(), $totalProducts),
                        'has_more' => $page < $lastPage,
                    ],
                ];
            });

            return response()->json($responsePayload);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $this->normalizeBackendError($e),
            ], 500);
        }
    }

    private function normalizeBackendError(\Throwable $e): string
    {
        $message = trim((string) $e->getMessage());
        $lower = strtolower($message);

        if (str_contains($lower, 'sqlstate[hy000] [2002]')) {
            return 'Database connection failed. Please verify DB host/port, SSL mode, and credentials.';
        }

        if (str_contains($lower, 'maximum execution time')) {
            return 'Sales report timed out for current filters. Please use a shorter date range.';
        }

        return $message === '' ? 'Unable to fetch product sales report' : $message;
    }

    private function resolveDateRange(string $dateFilter): ?array
    {
        $today = Carbon::today();

        switch ($dateFilter) {
            case 'today':
                return [
                    'from' => $today->copy()->startOfDay()->timestamp,
                    'to' => $today->copy()->endOfDay()->timestamp,
                ];
            case 'yesterday':
                return [
                    'from' => $today->copy()->subDay()->startOfDay()->timestamp,
                    'to' => $today->copy()->subDay()->endOfDay()->timestamp,
                ];
            case 'last_7_days':
            case 'last7':
                return [
                    'from' => $today->copy()->subDays(6)->startOfDay()->timestamp,
                    'to' => $today->copy()->endOfDay()->timestamp,
                ];
            case 'last_30_days':
            case 'last30':
                return [
                    'from' => $today->copy()->subDays(29)->startOfDay()->timestamp,
                    'to' => $today->copy()->endOfDay()->timestamp,
                ];
            default:
                return null;
        }
    }

    private function getPackageStatsForProducts(array $productIds, string $status, ?int $fromTs, ?int $toTs): array
    {
        if (empty($productIds)) {
            return [];
        }

        $query = DB::table('orders_item as oi')
            ->whereIn('oi.product_id', $productIds);

        $this->applyOrderExistsFilter($query, $status, $fromTs, $toTs, 'oi.order_id', false);

        $rows = $query
            ->select(
                'oi.product_id',
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.pi')), '') as package_id"),
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.ps')), JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.tx')), 'Default Package') as package_label"),
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.pu')), '') as package_unit"),
                DB::raw('COUNT(DISTINCT oi.order_id) as orders_count'),
                DB::raw('COALESCE(SUM(COALESCE(oi.qty_delivered, oi.quantity)), 0) as total_quantity'),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(DISTINCT oi.order_id ORDER BY oi.order_id DESC SEPARATOR ','), ',', 12) as order_ids_csv")
            )
            ->groupBy(
                'oi.product_id',
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.pi')), '')"),
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.ps')), JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.tx')), 'Default Package')"),
                DB::raw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(oi.pinfo, '$.pu')), '')")
            )
            ->get();

        $maxVisibleOrderIds = 12;
        $result = [];

        foreach ($rows as $row) {
            $productId = (int) ($row->product_id ?? 0);
            if ($productId <= 0) {
                continue;
            }

            $allOrderIds = collect(explode(',', (string) ($row->order_ids_csv ?? '')))
                ->map(fn($value) => (int) trim($value))
                ->filter(fn($value) => $value > 0)
                ->unique()
                ->values()
                ->all();

            $visibleOrderIds = array_slice($allOrderIds, 0, $maxVisibleOrderIds);
            $ordersCount = (int) ($row->orders_count ?? 0);

            if (!isset($result[$productId])) {
                $result[$productId] = [];
            }

            $result[$productId][] = [
                'package_id' => trim((string) ($row->package_id ?? '')),
                'package_label' => trim((string) ($row->package_label ?? '')) === ''
                    ? 'Default Package'
                    : trim((string) ($row->package_label ?? '')),
                'package_unit' => trim((string) ($row->package_unit ?? '')),
                'orders_count' => $ordersCount,
                'total_quantity' => (int) ($row->total_quantity ?? 0),
                'order_ids' => $visibleOrderIds,
                'more_order_ids_count' => max($ordersCount - count($visibleOrderIds), 0),
            ];
        }

        foreach ($result as $productId => $list) {
            usort($list, function ($a, $b) {
                if ($a['orders_count'] === $b['orders_count']) {
                    return strcmp((string) $a['package_label'], (string) $b['package_label']);
                }
                return $b['orders_count'] <=> $a['orders_count'];
            });

            $result[(int) $productId] = array_values($list);
        }

        return $result;
    }

    private function applyOrderExistsFilter(
        $query,
        string $status,
        ?int $fromTs,
        ?int $toTs,
        string $orderIdColumn,
        bool $includeAlternateOrderIds = true
    ): void
    {
        $query->whereExists(function ($exists) use ($status, $fromTs, $toTs, $orderIdColumn, $includeAlternateOrderIds) {
            $exists->select(DB::raw('1'))
                ->from('orders as o')
                ->where(function ($match) use ($orderIdColumn, $includeAlternateOrderIds) {
                    $match->whereColumn('o.order_id', $orderIdColumn);
                    if ($includeAlternateOrderIds) {
                        $match->orWhereColumn('o.master_order_id', $orderIdColumn)
                            ->orWhereColumn('o.bill_number', $orderIdColumn);
                    }
                });

            if ($status !== '' && $status !== 'all') {
                if ($status === 'pending') {
                    $exists->whereIn('o.order_state', ['registered', 'pending']);
                } else {
                    $exists->where('o.order_state', $status);
                }
            }

            if ($fromTs !== null) {
                $exists->where('o.start_time', '>=', $fromTs);
            }

            if ($toTs !== null) {
                $exists->where('o.start_time', '<=', $toTs);
            }
        });
    }

    private function decodeOrderItemPackage(string $rawPinfo): array
    {
        $decoded = json_decode($rawPinfo, true);

        if (!is_array($decoded)) {
            $decoded = json_decode(stripslashes($rawPinfo), true);
        }

        if (!is_array($decoded)) {
            return [
                'package_id' => '',
                'package_label' => 'Default Package',
                'package_unit' => '',
            ];
        }

        $packageLabel = trim((string) ($decoded['ps'] ?? $decoded['tx'] ?? ''));
        if ($packageLabel === '') {
            $packageLabel = 'Default Package';
        }

        return [
            'package_id' => trim((string) ($decoded['pi'] ?? '')),
            'package_label' => $packageLabel,
            'package_unit' => trim((string) ($decoded['pu'] ?? '')),
        ];
    }

    private function decodeProductPackages($rawPacks): array
    {
        if (is_array($rawPacks)) {
            $decoded = $rawPacks;
        } else {
            $decoded = json_decode((string) $rawPacks, true);
            if (!is_array($decoded)) {
                $decoded = json_decode(stripslashes((string) $rawPacks), true);
            }
        }

        if (!is_array($decoded)) {
            return [];
        }

        $packages = [];
        foreach ($decoded as $pack) {
            if (!is_array($pack)) {
                continue;
            }

            $label = trim((string) ($pack['ps'] ?? $pack['tx'] ?? ''));
            if ($label === '') {
                continue;
            }

            $packages[] = [
                'package_id' => trim((string) ($pack['pi'] ?? '')),
                'package_label' => $label,
                'package_unit' => trim((string) ($pack['pu'] ?? '')),
            ];
        }

        return array_values($packages);
    }

    private function tableExists(string $table): bool
    {
        $cacheKey = "table:$table";
        if (!array_key_exists($cacheKey, $this->schemaCache)) {
            try {
                $this->schemaCache[$cacheKey] = Schema::hasTable($table);
            } catch (\Throwable $e) {
                $this->schemaCache[$cacheKey] = false;
            }
        }
        return (bool) $this->schemaCache[$cacheKey];
    }

    // GET /api/products
    public function index(Request $request)
    {
        try {
            $query = Product::with(['category', 'parentCategory']);

            // Filter by specific subcategory (cat_id)
            if ($request->has('cat_id') && !empty($request->cat_id)) {
                $query->where('cat_id', $request->cat_id);
            }
            // Filter by parent category (parent_cat_id) - shows all products in that category and its subcategories
            elseif ($request->has('parent_cat_id') && !empty($request->parent_cat_id)) {
                $parentCatId = $request->parent_cat_id;

                // Collect all descendant category IDs, not just direct children.
                // Some category trees in the data can be deeper than one level.
                $categoryIds = [$parentCatId];
                $frontier = [$parentCatId];

                while (!empty($frontier)) {
                    $childIds = \DB::table('categories')
                        ->whereIn('parent_cat_id', $frontier)
                        ->pluck('cat_id')
                        ->toArray();

                    $childIds = array_values(array_diff($childIds, $categoryIds));
                    if (empty($childIds)) {
                        break;
                    }

                    $categoryIds = array_merge($categoryIds, $childIds);
                    $frontier = $childIds;
                }

                $query->whereIn('cat_id', $categoryIds);
            }

            // Additional filters
            if ($request->has('published') && $request->published !== null) {
                $query->where('is_published', $request->published === 'true' ? 1 : 0);
            }

            if ($request->has('in_stock') && $request->in_stock !== null) {
                $query->where('in_stock', $request->in_stock === 'true' ? 1 : 0);
            }

            // Search filter (apply before limit)
            if ($request->has('search') && !empty($request->search)) {
                $tokens = array_values(array_filter(explode(' ', trim($request->search)), fn($t) => $t !== ''));
                foreach ($tokens as $token) {
                    $query->where(function ($q) use ($token) {
                        $q->where('name', 'LIKE', "%$token%")
                          ->orWhere('brand', 'LIKE', "%$token%")
                          ->orWhere('description', 'LIKE', "%$token%")
                          ->orWhere('keywords', 'LIKE', "%$token%");
                    });
                }
            }

            // Limit results to prevent memory issues
            $limit = (int) $request->get('limit', 500);
            $limit = min(max($limit, 1), 1000); // Between 1 and 1000

            $products = $query->limit($limit)->get();

            // Ensure proper JSON encoding
            return response()->json([
                'status' => true,
                'data' => $products,
                'count' => $products->count()
            ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/products/{id}
    public function show($id)
    {
        try {
            $product = Product::with(['category', 'parentCategory', 'hsnCode', 'photos'])
                ->findOrFail($id);

            // Get applied taxes with tax details
            $appliedTaxes = \DB::table('product_taxes')
                ->join('taxes', 'product_taxes.tax_id', '=', 'taxes.id')
                ->where('product_taxes.product_id', $id)
                ->select(
                    'product_taxes.tax_id',
                    'product_taxes.tax_percent',
                    'taxes.tax_name',
                    'taxes.tax_category',
                    'taxes.tax_sub_category'
                )
                ->get();

            $productArray = $product->toArray();
            $productArray['applied_taxes'] = $appliedTaxes;

            return response()->json([
                'status' => true,
                'data' => $productArray
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    // POST /api/products/basic
    public function storeBasic(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'cat_id' => 'required|exists:categories,cat_id',
            'parent_cat_id' => 'required|integer|min:0', // Allow 0 for root categories
            'brand' => 'required|string|max:255',
            'description' => 'nullable|string',
            'keywords' => 'nullable|string',
            'hsn_code' => 'nullable|string|max:50',
            'gst_percent' => 'nullable|numeric|min:0|max:100',
            'seq_no' => 'nullable|integer|min:0',
            'ctype_id' => 'nullable|string|max:50',
            'start_date' => 'nullable|integer',
            'is_published' => 'nullable|integer|in:0,1',
            'is_used' => 'nullable|integer|in:0,1',
            'is_deleted' => 'nullable|integer|in:0,1',
            'in_stock' => 'nullable|integer|in:0,1',
            'inventory_type' => 'nullable|string|max:50',
            'inventory_unit_type' => 'nullable|string|max:50',
            'spec_params' => 'nullable|string',
            'packs' => 'nullable|string',
            'default_pack_id' => 'nullable|string|max:50',
            'offers' => 'nullable',
            'cache_txt' => 'nullable|string',
            'img_last_updated' => 'nullable|integer',
            'stock' => 'nullable|numeric',
            'stock_ut_id' => 'nullable|string|max:50',
            'display_photo_base64' => 'nullable|string',
            'taxes' => 'nullable|array',
            'taxes.*.tax_id' => 'required|exists:taxes,id',
            'taxes.*.tax_percent' => 'required|numeric|min:0|max:100',
            'order_limit' => 'nullable|integer|min:0',
            'buffer_limit' => 'nullable|integer|min:0',
        ]);

        // Custom validation for parent_cat_id
        if ($request->parent_cat_id > 0) {
            $parentExists = \DB::table('categories')->where('cat_id', $request->parent_cat_id)->exists();
            if (!$parentExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation failed',
                    'errors' => ['parent_cat_id' => ['The selected parent category does not exist.']]
                ], 400);
            }
        }

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        \DB::beginTransaction();

        try {
            // Prepare data for creation
            $productData = $validator->validated();

            // Extract taxes data before inserting product
            $taxesData = $productData['taxes'] ?? [];
            unset($productData['taxes']);

            // Serialize manual ID generation to reduce duplicate-ID risk under concurrent writes.
            $maxId = \DB::table('product')->lockForUpdate()->max('product_id') ?? 0;
            $productData['product_id'] = $maxId + 1;

            // Handle image upload if provided
            if ($request->has('display_photo_base64') && !empty($request->display_photo_base64)) {
                try {
                    $imageBase64 = $request->display_photo_base64;

                    // Remove data URL prefix if present
                    if (strpos($imageBase64, ',') !== false) {
                        $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
                    }

                    // Decode and save
                    $imageData = base64_decode($imageBase64);
                    if ($imageData && strlen($imageData) > 50) {
                        // Generate filename
                        $fileName = 'product_' . $productData['product_id'] . '_' . time() . '.png';
                        $uploadPath = storage_path('app/uploads/products');

                        // Create directory if needed
                        if (!is_dir($uploadPath)) {
                            mkdir($uploadPath, 0755, true);
                        }

                        // Save file
                        if (file_put_contents($uploadPath . '/' . $fileName, $imageData)) {
                            $productData['display_photo'] = '/uploads/products/' . $fileName;
                            $productData['img_last_updated'] = time();
                        }
                    }
                } catch (\Exception $imageError) {
                    // Continue without image if there's an error
                    // Don't fail the whole creation
                }
            }

            // Remove the base64 field as it's not a database column
            unset($productData['display_photo_base64']);

            // Set default values for required fields if not provided
            $productData['start_date'] = $productData['start_date'] ?? time();
            $productData['is_published'] = $productData['is_published'] ?? 0;
            $productData['is_used'] = $productData['is_used'] ?? 1;
            $productData['is_deleted'] = $productData['is_deleted'] ?? 0;
            $productData['in_stock'] = $productData['in_stock'] ?? 0;
            $productData['inventory_type'] = $productData['inventory_type'] ?? 'SINGLE';
            $productData['inventory_unit_type'] = $productData['inventory_unit_type'] ?? 'WEIGHT';
            $productData['ctype_id'] = $productData['ctype_id'] ?? 'vegetables_fruits';
            $productData['seq_no'] = $productData['seq_no'] ?? 0;
            $productData['gst_percent'] = $productData['gst_percent'] ?? 0;
            $productData['spec_params'] = $productData['spec_params'] ?? '[]';
            $productData['hsn_code'] = $productData['hsn_code'] ?? ''; // Ensure empty string, not null
            $productData['keywords'] = $productData['keywords'] ?? ''; // Ensure empty string, not null
            $productData['description'] = $productData['description'] ?? ''; // Ensure empty string, not null

            // Handle packages - ensure it's a valid JSON string
            if (isset($productData['packs'])) {
                if (is_array($productData['packs'])) {
                    $productData['packs'] = json_encode($productData['packs']);
                } elseif (!is_string($productData['packs'])) {
                    $productData['packs'] = '[]';
                }
            } else {
                $productData['packs'] = '[]';
            }

            $productData['default_pack_id'] = $productData['default_pack_id'] ?? '';
            $productData['cache_txt'] = $productData['cache_txt'] ?? '';
            $productData['img_last_updated'] = $productData['img_last_updated'] ?? 0;

            // Use DB::table()->insert() instead of Eloquent create() to handle manual ID
            \DB::table('product')->insert($productData);

            // Insert product taxes
            if (!empty($taxesData)) {
                foreach ($taxesData as $tax) {
                    $nextTaxRowId = (int) (\DB::table('product_taxes')->max('id') ?? 0) + 1;
                    \DB::table('product_taxes')->insert([
                        'id' => $nextTaxRowId,
                        'product_id' => $productData['product_id'],
                        'tax_id' => $tax['tax_id'],
                        'tax_percent' => $tax['tax_percent'],
                        'created_at' => now(),
                    ]);
                }
            }

            \DB::commit();

            // Return product with relationships - use fresh() to reload from database
            $product = Product::with(['category', 'parentCategory'])->find($productData['product_id']);

            return response()->json([
                'status' => true,
                'message' => 'Product created successfully',
                'data' => $product
            ], 201);
        } catch (\Exception $e) {
            \DB::rollBack();
            return response()->json([
                'status' => false,
                'message' => 'Failed to create product: ' . $e->getMessage()
            ], 500);
        }
    }

    // PUT /api/products/{id}/details
    public function updateDetails(Request $request, $id)
    {
        // Log incoming request for debugging numeric cast issues (will be removed after root cause is found)
        try {
            Log::info('ProductController::updateDetails incoming', [
                'product_id' => $id,
                'body' => $request->all(),
                'raw' => @file_get_contents('php://input'),
                'headers' => $request->headers->all(),
            ]);
        } catch (\Throwable $_) {
            // swallow logging errors to avoid interfering with normal flow
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'brand' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'keywords' => 'nullable|string',
            'hsn_code' => 'nullable|string|max:50',
            'gst_percent' => 'nullable|numeric|min:0|max:100',
            'stock' => 'nullable|numeric',
            'stock_ut_id' => 'nullable',
            'is_published' => 'nullable',
            'in_stock' => 'nullable',
            'cat_id' => 'nullable|exists:categories,cat_id',
            'parent_cat_id' => 'nullable|integer|min:0',
            'seq_no' => 'nullable|integer|min:0',
            'ctype_id' => 'nullable|string|max:50',
            'inventory_type' => 'nullable|string|max:50',
            'inventory_unit_type' => 'nullable|string|max:50',
            'display_photo_base64' => 'nullable|string',
            'spec_params' => 'nullable|array',
            'taxes' => 'nullable|array',
            'taxes.*.tax_id' => 'required|exists:taxes,id',
            'taxes.*.tax_percent' => 'required|numeric|min:0|max:100',
            'order_limit' => 'nullable|integer|min:0',
            'buffer_limit' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $product = Product::findOrFail($id);

            // Prepare update data
            $updateData = [];

            // Handle basic fields
            if ($request->has('name'))
                $updateData['name'] = $request->name;
            if ($request->has('brand'))
                $updateData['brand'] = $request->brand;
            if ($request->has('description'))
                $updateData['description'] = $request->description;
            if ($request->has('keywords'))
                $updateData['keywords'] = $request->keywords;
            if ($request->has('hsn_code'))
                $updateData['hsn_code'] = $request->hsn_code ?: '';
            if ($request->has('gst_percent'))
                $updateData['gst_percent'] = $request->gst_percent;
            if ($request->has('cat_id'))
                $updateData['cat_id'] = $request->cat_id;
            if ($request->has('parent_cat_id'))
                $updateData['parent_cat_id'] = $request->parent_cat_id;
            if ($request->has('seq_no'))
                $updateData['seq_no'] = $request->seq_no;
            if ($request->has('ctype_id'))
                $updateData['ctype_id'] = $request->ctype_id;
            if ($request->has('inventory_type'))
                $updateData['inventory_type'] = $request->inventory_type;
            if ($request->has('inventory_unit_type'))
                $updateData['inventory_unit_type'] = $request->inventory_unit_type;
            if ($request->has('spec_params'))
                $updateData['spec_params'] = json_encode($request->spec_params);
            if ($request->has('order_limit'))
                $updateData['order_limit'] = $request->order_limit;
            if ($request->has('buffer_limit'))
                $updateData['buffer_limit'] = $request->buffer_limit;

            // Handle stock fields explicitly
            if ($request->has('stock'))
                $updateData['stock'] = $request->stock;
            if ($request->has('stock_ut_id'))
                $updateData['stock_ut_id'] = (string)$request->stock_ut_id;

            // Handle boolean fields (convert to 1/0 for database)
            if ($request->has('is_published')) {
                $isPublished = $request->is_published;
                if (is_bool($isPublished)) {
                    $updateData['is_published'] = $isPublished ? 1 : 0;
                } else {
                    // Handle string/integer values
                    $updateData['is_published'] = in_array($isPublished, [1, '1', 'true', true]) ? 1 : 0;
                }
            }
            if ($request->has('in_stock')) {
                $inStock = $request->in_stock;
                if (is_bool($inStock)) {
                    $updateData['in_stock'] = $inStock ? 1 : 0;
                } else {
                    // Handle string/integer values
                    $updateData['in_stock'] = in_array($inStock, [1, '1', 'true', true]) ? 1 : 0;
                }
            }

            // Handle image upload if provided
            if ($request->has('display_photo_base64') && !empty($request->display_photo_base64)) {
                try {
                    $imageBase64 = $request->display_photo_base64;

                    // Remove data URL prefix if present
                    if (strpos($imageBase64, ',') !== false) {
                        $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
                    }

                    // Decode and save
                    $imageData = base64_decode($imageBase64);
                    if ($imageData && strlen($imageData) > 50) {
                        // Generate filename
                        $fileName = 'product_' . $id . '_' . time() . '.png';
                        $uploadPath = storage_path('app/uploads/products');

                        // Create directory if needed
                        if (!is_dir($uploadPath)) {
                            mkdir($uploadPath, 0755, true);
                        }

                        // Save file
                        if (file_put_contents($uploadPath . '/' . $fileName, $imageData)) {
                            $updateData['display_photo'] = '/uploads/products/' . $fileName;
                            $updateData['img_last_updated'] = time();
                        }
                    }
                } catch (\Exception $imageError) {
                    // Continue without image if there's an error
                    // Don't fail the whole update
                }
            }

            \DB::beginTransaction();

            try {
                // Update the product
                try {
                    $product->update($updateData);
                } catch (\Illuminate\Database\QueryException $qe) {
                    $message = $qe->getMessage();
                    if (str_contains($message, 'Truncated incorrect DOUBLE value')) {
                        $fallbackData = $updateData;

                        // Remove obvious placeholder values that can break numeric casts.
                        foreach (['ctype_id', 'gst_percent', 'seq_no', 'stock'] as $field) {
                            if (array_key_exists($field, $fallbackData)) {
                                $raw = $fallbackData[$field];
                                if (is_string($raw) && trim($raw) === '?') {
                                    unset($fallbackData[$field]);
                                }
                            }
                        }

                        // If DB currently stores numeric ctype_id, avoid sending a text cart type.
                        if (array_key_exists('ctype_id', $fallbackData)) {
                            $currentCtype = (string) ($product->getRawOriginal('ctype_id') ?? '');
                            $incomingCtype = (string) ($fallbackData['ctype_id'] ?? '');
                            if ($currentCtype !== '' && is_numeric($currentCtype) && !is_numeric($incomingCtype)) {
                                unset($fallbackData['ctype_id']);
                            }
                        }

                        // Retry once with sanitized fallback payload.
                        if ($fallbackData !== $updateData) {
                            \Log::warning('Product update fallback applied after DOUBLE cast error', [
                                'product_id' => $id,
                                'removed_fields' => array_values(array_diff(array_keys($updateData), array_keys($fallbackData))),
                                'error' => $message,
                            ]);
                            $product->update($fallbackData);
                        } else {
                            throw $qe;
                        }
                    } else {
                        throw $qe;
                    }
                }

                // Handle taxes update if provided
                if ($request->has('taxes')) {
                    // Delete existing taxes
                    \DB::table('product_taxes')->where('product_id', $id)->delete();

                    // Insert new taxes
                    $taxesData = $request->taxes;
                    if (!empty($taxesData)) {
                        foreach ($taxesData as $tax) {
                            $nextTaxRowId = (int) (\DB::table('product_taxes')->max('id') ?? 0) + 1;
                            \DB::table('product_taxes')->insert([
                                'id' => $nextTaxRowId,
                                'product_id' => $id,
                                'tax_id' => $tax['tax_id'],
                                'tax_percent' => $tax['tax_percent'],
                                'created_at' => now(),
                            ]);
                        }
                    }
                }

                \DB::commit();

                // Return updated product with relationships
                $updatedProduct = Product::with(['category', 'parentCategory'])->find($id);

                return response()->json([
                    'status' => true,
                    'message' => 'Product updated successfully',
                    'data' => $updatedProduct
                ]);
            } catch (\Exception $innerException) {
                \DB::rollBack();
                throw $innerException;
            }
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to update product: ' . $e->getMessage()
            ], 500);
        }
    }

    // PUT /api/products/{id}/packages
    public function updatePackages(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'packs' => 'required|array',
            'default_pack_id' => 'nullable',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $product = Product::findOrFail($id);
            $product->update($validator->validated());

            return response()->json([
                'status' => true,
                'message' => 'Product packages updated successfully',
                'data' => $product->fresh()
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    // PATCH /api/products/{id}/toggle-status
    public function toggleStatus($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->is_published = !$product->is_published;
            $product->save();

            return response()->json([
                'status' => true,
                'message' => 'Product status toggled successfully',
                'data' => $product
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }

    // POST /api/products/{id}/photos
    public function addPhoto(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'image_base64' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Check if product exists
            $product = Product::findOrFail($id);

            $imageBase64 = $request->image_base64;

            // Remove data URL prefix if present
            if (strpos($imageBase64, ',') !== false) {
                $imageBase64 = substr($imageBase64, strpos($imageBase64, ',') + 1);
            }

            // Decode and validate image
            $imageData = base64_decode($imageBase64);
            if (!$imageData || strlen($imageData) < 50) {
                return response()->json([
                    'status' => false,
                    'message' => 'Invalid image data'
                ], 400);
            }

            // Get next photo ID
            $maxPhotoId = \DB::table('product_photos')->max('photo_id') ?? 0;
            $photoId = $maxPhotoId + 1;

            // Generate filename
            $fileName = 'product_' . $id . '_photo_' . $photoId . '_' . time() . '.png';
            $uploadPath = storage_path('app/uploads/products');

            // Create directory if needed
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0755, true);
            }

            // Save file
            $filePath = $uploadPath . '/' . $fileName;
            if (!file_put_contents($filePath, $imageData)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to save image file'
                ], 500);
            }

            // Save to database
            $photoData = [
                'product_id' => $id,
                'photo_id' => $photoId,
                'file_location' => '/uploads/products/' . $fileName,
                'photo_slug' => 'photo_' . $photoId,
            ];

            \DB::table('product_photos')->insert($photoData);

            // Return the created photo
            $photo = \DB::table('product_photos')->where('photo_id', $photoId)->first();

            return response()->json([
                'status' => true,
                'message' => 'Photo added successfully',
                'data' => [
                    'product_id' => (int) $photo->product_id,
                    'photo_id' => (int) $photo->photo_id,
                    'file_location' => $photo->file_location,
                    'photo_slug' => $photo->photo_slug,
                ]
            ], 201);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to add photo: ' . $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/products/photos/{photoId}
    public function deletePhoto($photoId)
    {
        try {
            // Find the photo
            $photo = \DB::table('product_photos')->where('photo_id', $photoId)->first();

            if (!$photo) {
                return response()->json([
                    'status' => false,
                    'message' => 'Photo not found'
                ], 404);
            }

            // Delete file if it exists
            $filePath = storage_path('app' . $photo->file_location);
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Delete from database
            \DB::table('product_photos')->where('photo_id', $photoId)->delete();

            return response()->json([
                'status' => true,
                'message' => 'Photo deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to delete photo: ' . $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/products/{id}
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            $product->delete();

            return response()->json([
                'status' => true,
                'message' => 'Product deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Product not found'
            ], 404);
        }
    }
}
