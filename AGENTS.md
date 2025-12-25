# Repository Organization Guidelines

This document outlines the file organization standards for this repository. These guidelines should be followed when restructuring or adding new code.

## Core Principles

**Laravel's default directory structure should be respected for certain core components.**

## What Stays Where

### Controllers
- **Location:** `app/Http/Controllers/`
- **Reasoning:** Laravel's standard location, used by framework components and route resolution
- **Examples:**
  - `app/Http/Controllers/Api/Auth/OAuthController.php`
  - `app/Http/Controllers/Api/Users/UserController.php`

### Eloquent Models
- **Location:** `app/Models/`
- **Reasoning:** Laravel's standard location, conventions, and framework expectations
- **Organization:** Can be grouped by domain in subdirectories
- **Examples:**
  - `app/Models/User.php`
  - `app/Models/OAuth/Token.php`
  - `app/Models/OAuth/Client.php`

### Middleware
- **Location:** `app/Http/Middleware/`
- **Reasoning:** Laravel's standard location, registered in Kernel.php
- **Examples:**
  - `app/Http/Middleware/ValidateOAuthToken.php`
  - `app/Http/Middleware/CheckOAuthScopes.php`

### Database Components
- **Migrations:** `database/migrations/`
- **Seeders:** `database/seeders/`
- **Factories:** `database/factories/`
- **Reasoning:** Laravel's standard database structure

## What Goes in Modules

The `app/Modules/` directory is for **domain logic, services, and business rules** that are not framework-bound components.

### Use Cases for Modules

**Business Logic & Services:**
- Service classes
- Domain services
- Business rules
- Complex algorithms

**Guards & Authentication:**
- Custom auth guards
- Authentication services
- Authorization logic

**Commands:**
- Artisan commands (domain-specific)

**Repositories & Contracts:**
- Data access layer abstractions
- Interface definitions

**Entities & DTOs:**
- Non-Eloquent entities
- Data transfer objects
- Value objects

### Module Structure Example

```
app/Modules/Auth/OAuth/
├── Contracts/           # Interfaces
├── Entities/           # Non-Eloquent entities (League OAuth entities)
├── Repositories/       # Repository implementations
├── Grants/             # Custom OAuth grants
├── Guards/             # Custom auth guards
├── Services/           # Business logic services
├── Commands/           # Artisan commands
└── OAuthServiceProvider.php
```

## Decision Framework

When deciding where to place new code, ask:

1. **Is it a Controller?** → `app/Http/Controllers/`
2. **Is it an Eloquent Model?** → `app/Models/`
3. **Is it Middleware?** → `app/Http/Middleware/`
4. **Is it a migration/seeder/factory?** → `database/`
5. **Is it business logic/domain services?** → `app/Modules/`

## Refactoring Guidelines

When consolidating scattered code:

- **DO move** services, guards, commands to appropriate Modules
- **DON'T move** Controllers, Models, Middleware, or database files
- **ALWAYS update** namespace references throughout the codebase
- **UPDATE service providers** when moving classes
- **TEST thoroughly** after namespace changes

## Examples of Good Organization

```
app/
├── Http/
│   ├── Controllers/     # All controllers stay here
│   └── Middleware/      # All middleware stays here
├── Models/              # All Eloquent models stay here
│   ├── OAuth/
│   └── User.php
└── Modules/
    └── Auth/
        ├── OAuth/       # OAuth domain logic
        │   ├── Contracts/
        │   ├── Services/
        │   ├── Guards/
        │   └── Repositories/
        └── Webauthn/    # Webauthn domain logic
            ├── Actions/
            └── Services/
```

This keeps framework-bound components in their standard locations while organizing domain logic in Modules.
