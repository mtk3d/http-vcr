<?php

declare(strict_types=1);

namespace HttpVcr\Tests\Unit\Scope;

use HttpVcr\Scope\NullScopeResolver;
use HttpVcr\Scope\RegexUrlScopeResolver;
use InvalidArgumentException;
use Nyholm\Psr7\Request;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RegexUrlScopeResolver::class)]
#[CoversClass(NullScopeResolver::class)]
final class RegexUrlScopeResolverTest extends TestCase
{
    public function testItReadsTheNamedGroupOutOfTheUri(): void
    {
        $resolver = new RegexUrlScopeResolver('#/admin/api/(?<scope>\d{4}-\d{2})/#');

        self::assertSame('2024-01', $resolver->resolve(
            new Request('GET', 'https://shop.example.com/admin/api/2024-01/products/1.json'),
        ));
    }

    public function testAUriThePatternDoesNotMatchIsUnscoped(): void
    {
        $resolver = new RegexUrlScopeResolver('#/v(?<scope>\d+)/#');

        self::assertNull($resolver->resolve(new Request('GET', 'https://shop.example.com/oauth/token')));
    }

    public function testAPatternThatDoesNotCompileIsRefusedWhereItIsWritten(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not a valid regular expression');

        new RegexUrlScopeResolver('#/v(?<scope>\d+/#');
    }

    public function testAPatternWithoutAScopeGroupSaysSoOnTheRequestThatMatchedIt(): void
    {
        $resolver = new RegexUrlScopeResolver('#/v(\d+)/#');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no group named "scope"');

        $resolver->resolve(new Request('GET', 'https://shop.example.com/v2/products'));
    }

    public function testTheNullResolverNeverScopesAnything(): void
    {
        self::assertNull((new NullScopeResolver())->resolve(new Request('GET', 'https://shop.example.com/v2/x')));
    }
}
