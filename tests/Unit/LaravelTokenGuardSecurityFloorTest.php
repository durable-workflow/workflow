<?php

declare(strict_types=1);

namespace Tests\Unit;

use Composer\InstalledVersions;
use Illuminate\Auth\TokenGuard;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class LaravelTokenGuardSecurityFloorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $version = InstalledVersions::getVersion('laravel/framework');
        if (! is_string($version) || version_compare($version, '10.0.0', '<')) {
            $this->markTestSkipped('Laravel 9 retains an explicit upgrade/drain-only security disposition.');
        }
    }

    public function testNonStringTokenNeverReachesTheUserLookup(): void
    {
        $provider = $this->createMock(UserProvider::class);
        $provider->expects($this->never())
            ->method('retrieveByCredentials');
        $guard = new TokenGuard($provider, Request::create('/', 'GET', [
            'api_token' => [0],
        ]));

        $this->assertNull($guard->user());
    }

    public function testOrdinaryStringTokenRetainsItsNormalLookup(): void
    {
        $user = $this->createStub(Authenticatable::class);
        $provider = $this->createMock(UserProvider::class);
        $provider->expects($this->once())
            ->method('retrieveByCredentials')
            ->with([
                'api_token' => 'ordinary-token',
            ])->willReturn($user);
        $guard = new TokenGuard($provider, Request::create('/', 'GET', [
            'api_token' => 'ordinary-token',
        ]));

        $this->assertSame($user, $guard->user());
    }

    public function testNonStringCredentialsNeverReachTheUserLookup(): void
    {
        $provider = $this->createMock(UserProvider::class);
        $provider->expects($this->never())
            ->method('retrieveByCredentials');
        $guard = new TokenGuard($provider, Request::create('/'));

        $this->assertFalse($guard->validate([
            'api_token' => [0],
        ]));
    }

    public function testOrdinaryStringCredentialsRetainTheirNormalValidation(): void
    {
        $user = $this->createStub(Authenticatable::class);
        $provider = $this->createMock(UserProvider::class);
        $provider->expects($this->once())
            ->method('retrieveByCredentials')
            ->with([
                'api_token' => 'ordinary-token',
            ])->willReturn($user);
        $guard = new TokenGuard($provider, Request::create('/'));

        $this->assertTrue($guard->validate([
            'api_token' => 'ordinary-token',
        ]));
    }
}
