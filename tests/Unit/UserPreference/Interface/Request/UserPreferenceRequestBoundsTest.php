<?php

declare(strict_types=1);

namespace App\Tests\Unit\UserPreference\Interface\Request;

use App\UserPreference\Interface\Request\CreateEqDeviceProfileRequest;
use App\UserPreference\Interface\Request\UpdateEqDeviceProfileRequest;
use App\UserPreference\Interface\Request\UpdateSidebarSectionsRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class UserPreferenceRequestBoundsTest extends TestCase
{
    public function testEqDeviceIdIsBoundedTo255Characters(): void
    {
        self::assertSame([], $this->paths(new CreateEqDeviceProfileRequest('Headphones', 'headphones', str_repeat('ø', 255))));
        self::assertSame(['deviceId'], $this->paths(new CreateEqDeviceProfileRequest('Headphones', 'headphones', str_repeat('a', 256))));
        self::assertSame(['deviceId'], $this->paths(new UpdateEqDeviceProfileRequest(deviceId: str_repeat('a', 256))));
    }

    public function testEqPayloadIsBoundedTo16KibOfCompactJson(): void
    {
        // {"bands":"…"} is 12 bytes around the value.
        self::assertSame([], $this->paths(new UpdateEqDeviceProfileRequest(payload: ['bands' => str_repeat('a', 16384 - 12)])));
        self::assertSame(['payload'], $this->paths(new UpdateEqDeviceProfileRequest(payload: ['bands' => str_repeat('a', 16384 - 11)])));
    }

    public function testTheWebClientsSidebarLayoutIsAccepted(): void
    {
        self::assertSame([], $this->paths(new UpdateSidebarSectionsRequest([
            [
                'id' => 'music-quick-jump',
                'label' => 'Quick Jump',
                'type' => 'navigation',
                'items' => [
                    ['id' => 'music-home', 'type' => 'page_link', 'label' => 'Home', 'icon' => 'home', 'config' => ['route' => '/music']],
                    ['id' => 'panel_action-1', 'type' => 'panel_action', 'label' => 'Queue', 'icon' => 'list', 'config' => ['tab' => 'queue']],
                ],
            ],
        ])));
    }

    public function testSidebarSectionsAndItemsAreCountedAndTheLayoutIsBoundedTo16Kib(): void
    {
        $item = ['id' => 'i', 'type' => 'page_link', 'label' => 'l', 'icon' => 'h', 'config' => []];

        self::assertContains('sections', $this->paths(new UpdateSidebarSectionsRequest(array_fill(0, 101, ['id' => 's']))));
        self::assertContains('sections[0][items]', $this->paths(new UpdateSidebarSectionsRequest([['items' => array_fill(0, 101, $item)]])));
        self::assertSame(['sections'], $this->paths(new UpdateSidebarSectionsRequest([
            ['id' => 's', 'label' => str_repeat('a', 16384), 'items' => []],
        ])));
    }

    public function testSidebarItemsOfAnotherShapeAreRefused(): void
    {
        // As the JSON decoder hands the body over, before validation.
        $sections = json_decode(
            '[{"items": [{"id": ["nested"], "type": "page_link", "label": "l", "icon": "h"}, "item"], "label": "kept"}, "section"]',
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $paths = $this->paths(new UpdateSidebarSectionsRequest($sections));

        self::assertSame(['sections[0][items][0][id]', 'sections[0][items][1]', 'sections[1]'], $paths);
    }

    /** @return list<string> */
    private function paths(object $request): array
    {
        $paths = [];
        foreach ($this->validator()->validate($request) as $violation) {
            $paths[] = $violation->getPropertyPath();
        }

        return array_values(array_unique($paths));
    }

    private function validator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }
}
