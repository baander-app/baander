<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Swoole;

use Egulias\EmailValidator\EmailValidator;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ProxyManager\Configuration;
use ProxyManager\GeneratorStrategy\EvaluatingGeneratorStrategy;
use Swoole\Coroutine;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\Generator;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Mime\MimeAddressValidatorInstaller;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutex;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\RfcComplianceException;

final class FrameworkIsolationMimeTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testAddressUsesContextualValidatorsWithoutSharingEarlierValidationErrors(): void
    {
        $pool = new UnmanagedFactoryServicePool(static fn(): object => new EmailValidator(), new Swoole(), new ChannelMutex(), 2);
        $configuration = new Configuration();
        $configuration->setGeneratorStrategy(new EvaluatingGeneratorStrategy());
        $validator = (new Generator($configuration))->createProxy($pool, EmailValidator::class);
        self::assertInstanceOf(EmailValidator::class, $validator);
        $installer = new MimeAddressValidatorInstaller($validator);
        $installer->boot();
        $installer->boot();
        self::assertSame($validator, (new \ReflectionProperty(Address::class, 'validator'))->getValue());
        $errors = [];
        $instances = [];
        \Swoole\Coroutine\run(static function () use ($pool, $validator, &$errors, &$instances): void {
            $invalidReady = new Coroutine\Channel(1);
            $validFinished = new Coroutine\Channel(1);
            Coroutine::create(static function () use ($pool, $validator, $invalidReady, $validFinished, &$errors, &$instances): void {
                try {
                    new Address('invalid-address');
                } catch (RfcComplianceException) {
                    $errors['invalid'] = $validator->getError();
                }
                $instances[] = $pool->get();
                $invalidReady->push(true);
                $validFinished->pop();
                $errors['invalidAfterOtherRequest'] = $validator->getError();
                $pool->releaseFromCoroutine(Coroutine::getCid());
            });
            Coroutine::create(static function () use ($pool, $validator, $invalidReady, $validFinished, &$errors, &$instances): void {
                $invalidReady->pop();
                new Address('member@baander.app');
                $instances[] = $pool->get();
                $errors['valid'] = $validator->getError();
                $validFinished->push(true);
                $pool->releaseFromCoroutine(Coroutine::getCid());
            });
        });
        self::assertCount(2, $instances);
        self::assertNotSame($instances[0], $instances[1]);
        self::assertNotNull($errors['invalid']);
        self::assertSame($errors['invalid'], $errors['invalidAfterOtherRequest']);
        self::assertNull($errors['valid']);
    }
}
