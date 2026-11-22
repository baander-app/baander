<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Interface\Controller;

use App\Shared\Application\Http\RequestLocale;
use App\Shared\Interface\Controller\ApiResponsesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/** An error helper called without a message answers its status's message in the request's language. */
final class ApiResponsesTraitTest extends TestCase
{
    /** @return iterable<string, array{string, string, int, string}> */
    public static function defaultMessages(): iterable
    {
        yield 'not found, en' => ['notFound', 'en', 404, 'Resource not found.'];
        yield 'not found, da' => ['notFound', 'da', 404, 'Ressource ikke fundet.'];
        yield 'unauthorized, en' => ['unauthorized', 'en', 401, 'Authentication required.'];
        yield 'unauthorized, da' => ['unauthorized', 'da', 401, 'Godkendelse påkrævet.'];
        yield 'forbidden, en' => ['forbidden', 'en', 403, 'Forbidden.'];
        yield 'forbidden, da' => ['forbidden', 'da', 403, 'Forbudt.'];
    }

    #[DataProvider('defaultMessages')]
    public function testAHelperWithoutAMessageAnswersInTheRequestsLanguage(string $helper, string $language, int $status, string $message): void
    {
        $response = self::answer($language, $helper);

        self::assertSame(['error' => ['message' => $message, 'code' => $status]], self::body($response, $status));
    }

    public function testAValidationFailureAnswersInTheRequestsLanguage(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should not be blank.', null, [], null, 'name', ''),
        ]);

        $response = self::answer('da', 'validationErrorResponse', $violations);

        self::assertSame(
            [
                'error' => [
                    'message' => 'Validering mislykkedes.',
                    'code' => 422,
                    'details' => ['name' => ['This value should not be blank.']],
                ],
            ],
            self::body($response, 422),
        );
    }

    public function testACallersOwnMessageIsKept(): void
    {
        $response = self::answer('da', 'notFound', 'Playlist not found.');

        self::assertSame(['error' => ['message' => 'Playlist not found.', 'code' => 404]], self::body($response, 404));
    }

    /** Calls the helper on a controller handling a request in the given language. */
    private static function answer(string $language, string $helper, mixed ...$arguments): JsonResponse
    {
        $translator = new Translator('en');
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['en', 'da'] as $locale) {
            $translator->addResource(
                'yaml',
                sprintf('%s/translations/messages+intl-icu.%s.yaml', dirname(__DIR__, 5), $locale),
                $locale,
                'messages+intl-icu',
            );
        }

        $request = new Request();
        $request->attributes->set(RequestLocale::ATTRIBUTE, $language);
        $requests = new RequestStack();
        $requests->push($request);

        $controller = new class {
            use ApiResponsesTrait {
                notFound as public;
                unauthorized as public;
                forbidden as public;
                validationErrorResponse as public;
            }
        };
        $controller->setTranslator($translator);
        $controller->setTranslationRequests($requests);

        $response = $controller->{$helper}(...$arguments);
        self::assertInstanceOf(JsonResponse::class, $response);

        return $response;
    }

    /** @return array<string, mixed> */
    private static function body(JsonResponse $response, int $status): array
    {
        self::assertSame($status, $response->getStatusCode());

        return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
