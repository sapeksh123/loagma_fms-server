# Laravel Backend - Migrated from Core PHP

This is a Laravel-based API backend, migrated from a custom core PHP application.

## 🚀 Quick Start

### 1. Install Dependencies
```bash
composer install
```

### 2. Configure Environment
Update `.env` with your database credentials:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 3. Generate Application Key
```bash
php artisan key:generate
```

### 4. Configure Storage (for file uploads)
```bash
php artisan storage:link
```

### 5. Start Development Server
```bash
php artisan serve
```

Your API will be available at: `http://localhost:8000/api/`

## 📚 Documentation

- **[SETUP_CHECKLIST.md](SETUP_CHECKLIST.md)** - Complete setup checklist
- **[MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)** - Detailed migration guide
- **[CHANGES_SUMMARY.md](CHANGES_SUMMARY.md)** - What changed and where
- **[ARCHITECTURE_COMPARISON.md](ARCHITECTURE_COMPARISON.md)** - Architecture comparison

## 🎯 API Endpoints

All endpoints are prefixed with `/api/`

### Categories
- `GET /categories` - Get all parent categories
- `GET /categories/active` - Get active categories
- `GET /categories/{id}` - Get specific category
- `POST /categories` - Create category
- `PUT /categories/{id}` - Update category
- `DELETE /categories/{id}` - Delete category

### Products
- `GET /products` - Get all products
- `GET /products/{id}` - Get specific product
- `POST /products/basic` - Create product
- `PUT /products/{id}/details` - Update product
- `DELETE /products/{id}` - Delete product

### HSN Codes
- `GET /hsn-codes` - Get all HSN codes
- `GET /hsn-codes/search?q=term` - Search HSN codes
- `POST /hsn-codes` - Create HSN code
- `PUT /hsn-codes/{id}` - Update HSN code
- `DELETE /hsn-codes/{id}` - Delete HSN code

## 🗄️ Database

This application uses your **existing database**. No migrations needed.

## 🧪 Testing

```bash
curl http://localhost:8000/api/test
curl http://localhost:8000/api/db-test
curl http://localhost:8000/api/categories
```

## ⚙️ After DB Env Changes

When you update DB host/port/SSL env values, run:

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan config:cache
```

Then warm up key endpoints:

```bash
curl "http://localhost:8000/api/orders?page=1&per_page=20"
curl "http://localhost:8000/api/products/sales-report?page=1&per_page=20&date_filter=last_30_days"
```

## 📖 Resources

- [Laravel Documentation](https://laravel.com/docs)
- [Laracasts](https://laracasts.com)
