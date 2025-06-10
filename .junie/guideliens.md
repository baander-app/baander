# Never use any terminal commands.
Dont even use `ls` or `cd`.

``` markdown

# Laravel Development Guidelines for Bånder

## Table of Contents
- [General Principles](#general-principles)
- [Code Standards](#code-standards)
- [Laravel Conventions](#laravel-conventions)
- [Database Guidelines](#database-guidelines)
- [API Development](#api-development)
- [Frontend Integration](#frontend-integration)
- [Testing Guidelines](#testing-guidelines)
- [Security Guidelines](#security-guidelines)
- [Performance Guidelines](#performance-guidelines)
- [Documentation Standards](#documentation-standards)

## General Principles

### Code Quality
- Write clean, readable, and maintainable code
- Follow SOLID principles
- Use meaningful variable and method names
- Keep methods small and focused on a single responsibility
- Favor composition over inheritance

### Version Control
- Use descriptive commit messages following conventional commits format
- Keep commits atomic and focused
- Use feature branches for new functionality
- Review code before merging to main branch

## Code Standards

### PHP Standards
- Follow PSR-12 coding standards
- Use PHP 8.4 features where appropriate (typed properties, match expressions, etc.)
- Use strict types declaration: `declare(strict_types=1);`
- Use return type declarations for all methods
- Use nullable types when appropriate: `?string`

### Naming Conventions
- **Classes**: PascalCase (`UserController`, `OrderService`)
- **Methods**: camelCase (`getUserData`, `processOrder`)
- **Variables**: camelCase (`$userEmail`, `$orderTotal`)
- **Constants**: SCREAMING_SNAKE_CASE (`MAX_RETRY_ATTEMPTS`)
- **Database tables**: snake_case, plural (`users`, `order_items`)
- **Database columns**: snake_case (`created_at`, `user_id`)

## Laravel Conventions

### Directory Structure
```
app/ ├── Actions/ # Single-purpose action classes ├── Console/ # Artisan commands ├── Events/ # Event classes ├── Exceptions/ # Custom exceptions ├── Http/ │ ├── Controllers/ # HTTP controllers │ ├── Middleware/ # Custom middleware │ ├── Requests/ # Form request validation │ └── Resources/ # API resources ├── Jobs/ # Queue jobs ├── Listeners/ # Event listeners ├── Mail/ # Mailable classes ├── Models/ # Eloquent models ├── Policies/ # Authorization policies ├── Providers/ # Service providers ├── Rules/ # Custom validation rules └── Services/ # Business logic services
``` 

### Controllers
- Keep controllers thin - delegate business logic to services
- Use form request classes for validation
- Return appropriate HTTP status codes
```
php class UserController extends Controller { public function __construct( private readonly UserService $userService ) {}
public function store(CreateUserRequest $request): JsonResponse
{
$user = $this->userService->createUser($request->validated());

    return response()->json($user, 201);
}
}
latex_unknown_tag
``` 

### Models
- Use Eloquent relationships instead of manual joins
- Define fillable or guarded properties
- Use model factories for testing
- Implement soft deletes when appropriate
```
php class User extends Authenticatable { use HasApiTokens, HasFactory, Notifiable, SoftDeletes;
protected $fillable = [
'name',
'email',
'password',
];

protected $hidden = [
'password',
'remember_token',
];

protected function casts(): array
{
return [
'email_verified_at' => 'datetime',
'password' => 'hashed',
];
}
}
``` 

### Services
- Create service classes for complex business logic
- Use dependency injection
- Make services stateless when possible
```
php class UserService { public function __construct( private readonly UserRepository userRepository, private readonly NotificationServicenotificationService ) {}
public function createUser(array $data): User
{
$user = $this->userRepository->create($data);
$this->notificationService->sendWelcomeEmail($user);

    return $user;
}
}
latex_unknown_tag
``` 

## Database Guidelines

### Migrations
- Use descriptive migration names
- Always include rollback logic in `down()` method
- Use foreign key constraints
- Add indexes for frequently queried columns
- Use appropriate data types
```
php public function up(): void { Schema::create('user_profiles', function (Blueprint table) {table->uuid('id')->primary(); table->foreignUuid('user_id')->constrained()->onDelete('cascade');table->string('first_name'); table->string('last_name');table->date('birth_date')->nullable(); $table->timestamps();
$table->index(['user_id']);
});
}
``` 

### Eloquent
- Use eager loading to prevent N+1 queries
- Use query scopes for reusable query logic
- Prefer Eloquent relationships over raw queries
- Use database transactions for multi-step operations
```
php // Good: Eager loading $users = User::with(['profile', 'orders'])->get();
// Good: Using query scopes class User extends Model { public function scopeActive(Builder query): void {query->where('is_active', true); } }
``` 

## API Development

### RESTful APIs
- Follow REST conventions for URL structure
- Use appropriate HTTP methods (GET, POST, PUT, DELETE)
- Return consistent JSON responses
- Implement proper error handling
- Use API resources for response formatting

### Authentication
- Use Laravel Sanctum for API authentication
- Implement proper token management
- Use middleware for route protection
```
php // API Resource example class UserResource extends JsonResource { public function toArray(Request request): array { return [ 'id' =>this->id, 'name' => this->name, 'email' =>this->email, 'created_at' => this->created_at, 'profile' => new ProfileResource(this->whenLoaded('profile')), ]; } }
latex_unknown_tag
``` 

## Frontend Integration

### React Integration
- Use Ziggy for route generation in JavaScript
- Implement proper error handling for API calls
- Use TypeScript for type safety
- Follow React best practices and hooks patterns

### Asset Management
- Use Vite for asset compilation
- Organize assets logically
- Optimize images and other media files
- Use SCSS for styling with proper organization

## Testing Guidelines

### PHPUnit Testing
- Write tests for all business logic
- Use factories for test data
- Mock external dependencies
- Test both happy path and edge cases
- Aim for high test coverage
```
php class UserServiceTest extends TestCase { use RefreshDatabase;
public function test_can_create_user(): void
{
$userData = [
'name' => 'John Doe',
'email' => 'john@example.com',
'password' => 'password123',
];

    $user = $this->userService->createUser($userData);

    $this->assertInstanceOf(User::class, $user);
    $this->assertEquals($userData['email'], $user->email);
}
}
``` 

### Test Organization
- Feature tests for end-to-end functionality
- Unit tests for individual components
- Use meaningful test method names
- Group related tests in test classes

## Security Guidelines

### Authentication & Authorization
- Use Laravel's built-in authentication features
- Implement proper authorization with policies
- Validate all user inputs
- Implement rate limiting

### Data Protection
- Hash sensitive data
- Use environment variables for secrets
- Implement proper CORS configuration
- Sanitize user inputs
- Use CSRF protection
```
php // Policy example class PostPolicy { public function update(User user, Postpost): bool { return user->id ===post->user_id; } }
``` 

## Performance Guidelines

### Database Optimization
- Use database indexing strategically
- Implement query optimization
- Use Redis for caching

### Caching Strategy
- Cache frequently accessed data
- Use appropriate cache drivers
- Implement cache invalidation strategies
- Use Redis for session storage and queues
```
php // Caching example public function getPopularPosts(): Collection { return Cache::remember('popular_posts', 3600, function () { return Post::with('author') ->where('views', '>', 1000) ->orderBy('views', 'desc') ->take(10) ->get(); }); }
``` 

### Queue Management
- Use queues for time-consuming tasks
- Implement proper job retry logic
- Monitor queue performance
- Use Redis as queue driver

## Documentation Standards

### Code Documentation
- Use PHPDoc blocks for all public methods
- Document complex business logic
- Keep documentation up to date
- Include examples in documentation

```php
/**
 * Create a new user account with the provided data.
 *
 * @param array $data User data including name, email, and password
 * @return User The newly created user instance
 * @throws ValidationException When user data is invalid
 */
public function createUser(array $data): User
{
    // Implementation
}
```
```
### API Documentation
- Document all API endpoints
- Include request/response examples
- Document authentication requirements
- Keep API documentation current

## Environment Configuration
### Development
- Use appropriate log levels
- Enable debug mode for development
- Use local database for development
- Configure proper error reporting

### Production
- Disable debug mode
- Use environment-specific configurations
- Implement proper logging
- Use production-optimized settings

## Best Practices Summary
1. **Follow Laravel conventions** - Use Laravel's built-in features and conventions
2. **Write testable code** - Design code that's easy to test and maintain
3. **Use dependency injection** - Leverage Laravel's service container
4. **Implement proper error handling** - Handle errors gracefully and provide meaningful messages
5. **Optimize for performance** - Use caching, queues, and database optimization
6. **Maintain security** - Follow security best practices and validate all inputs
7. **Document your code** - Write clear documentation and comments
8. **Stay consistent** - Follow established patterns and conventions throughout the project

## Tools and Resources
- **Code Quality**: PHPStan for static analysis
- **Testing**: PHPUnit with Mockery for mocking
- **API Testing**: Use built-in testing tools
- **Performance**: Use Laravel Telescope for debugging
- **Security**: Follow OWASP guidelines

Remember: These guidelines should evolve with the project and team needs. Regular reviews and updates ensure they remain relevant and useful.
``` 

This comprehensive guidelines document covers all the essential aspects of Laravel development for your Bånder application, including the specific technologies and frameworks you're using (Laravel 12.17.0, PostgreSQL, Redis, React with TypeScript, etc.). The guidelines are tailored to promote best practices, maintainability, and consistency across your development team.
```
