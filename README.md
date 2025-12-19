# Backend Project – Laravel 12 Sanctum API

## Overview

This repository contains the **Backend API** built with **Laravel 12**, designed for **production-ready**, scalable applications.  
It demonstrates **secure authentication**, **role-based authorization**, and **clean API architecture** suitable for real-world and enterprise systems.

---

## Technologies Used

- **Laravel 12** – API development framework  
- **MySQL** – Relational database  
- **Laravel Sanctum** – Token-based authentication  
- **Spatie Laravel Permission** – Role & permission management  

---

## Prerequisites

Ensure the following are installed on your system:

- **PHP 8.2 or higher**
- **Composer**
- **MySQL**
- **Node.js & NPM** (optional, for future frontend or asset builds)

---

## Installation

### 1. Clone the repository

```bash
git clone <repository-url>
````

### 2. Navigate to the project directory

```bash
cd <backend-directory>
```

### 3. Install PHP dependencies

```bash
composer install
```

### 4. Configure environment variables

Copy the example environment file:

```bash
cp .env.example .env
```

Update the following values in `.env`:

```env
DB_DATABASE=your_database_name
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password
```

---

### 5. Generate application key

```bash
php artisan key:generate
```

---

### 6. Run database migrations & seeders

```bash
php artisan migrate --seed
```

---

### 7. Start the development server

```bash
php artisan serve
```

The API will be available at:

```
http://127.0.0.1:8000
```

---

## Authentication

This project uses **Laravel Sanctum** for API authentication.

* Login returns a **Bearer token**
* Token must be sent with each request:

```http
Authorization: Bearer <token>
```

* Access is controlled via **roles & permissions**

---

## Authorization

Role and permission management is implemented using:

* **Spatie/laravel-permission**
* Middleware & policies for route protection
* Fine-grained permission checks at controller level

---

## Available Artisan Commands

* `php artisan migrate` – Run database migrations
* `php artisan db:seed` – Seed sample data
* `php artisan migrate:fresh --seed` – Reset database
* `php artisan make:controller` – Create controller
* `php artisan make:model` – Create model
* `php artisan make:migration` – Create migration
* `php artisan queue:work` – Process queued jobs

---

## Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   ├── Requests/
│   └── Middleware/
├── Models/
├── Policies/
├── Services/
├── Repositories/
├── Jobs/
database/
├── migrations/
├── seeders/
routes/
├── api.php
config/
docs/
```

This structure follows **clean architecture principles**, ensuring long-term maintainability.

---

## API Documentation

* Postman collection available in the `/docs/postman` directory
* Includes:

  * Authentication endpoints
  * User & role management
  * CRUD modules
  * Importer APIs

---

## Use Cases

* SaaS backends
* Admin dashboards
* Enterprise APIs
* Multi-role systems
* Scalable microservice foundations

---

## License

This project is licensed under the **MIT License**.

---

## Author

**Shamim Reza**
Full-Stack Developer
Laravel • Vue • React • Next.js • Python