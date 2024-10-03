<?php

declare(strict_types=1);

namespace Tests\Unit;

use Closure;
use Error;
use Fiber;
use Serializor\Codec;
use Serializor\SerializerError;
use Serializor\Stasis;
use Serializor\Transformers\Transformer;

use function ctype_xdigit;
use function explode;

covers(Codec::class);

describe('signing', function (): void {
    test('when a signature is empty a signature nothing will be prefixed to the resulting string', function (): void {
        $value = 'VALUE';
        $codec = new Codec('', []);

        [$actual] = explode('|', $codec->serialize($value), 2);

        expect($actual)->toBe($codec->serialize($value));
    });

    test('when a signature is non-empty a signature will be prefixed to the resulting string', function (): void {
        $value = 'VALUE';
        $codec = new Codec('%SECRET%', []);

        [$actual] = explode('|', $codec->serialize($value), 2);

        expect($actual)->toHaveLength(64);
        expect(ctype_xdigit($actual))->toBeTrue();
    });

    test('fails when provided signature does not match the secret', function (): void {
        $codec = new Codec('%SECRET%', []);

        $codec->unserialize('definitely not a signature|s:5:"VALUE";');
    })
        ->throws(SerializerError::class);

    test('deserializes data when secret is empty and no signature is provided', function (): void {
        $codec = new Codec('', []);

        $actual = $codec->unserialize('s:5:"VALUE";');

        expect($actual)->toBe('VALUE');
    });

    test('deserializes data when secret, value and signature match', function (): void {
        $codec = new Codec('%SECRET%', []);

        $actual = $codec->unserialize('f17a470c97ad1b297435dfa934e5628a219ededf9e6e8a410a17db7374cbb848|s:5:"VALUE";');

        expect($actual)->toBe('VALUE');
    });
});

test('serializes an unserializable value using a specific transformer', function (): void {
    $transformerSpy = new class extends TestTransformer {
        public mixed $transformedValue = null;

        public function transforms(mixed $value): bool
        {
            return true;
        }

        public function transform(mixed $value): Stasis
        {
            $this->transformedValue = $value;
            return new Stasis('');
        }
    };
    $codec = new Codec('', [$transformerSpy]);
    $expected = fn() => null;

    $codec->serialize($expected);
    $actual = $transformerSpy->transformedValue;

    expect($actual)->toBe($expected);
});

test('serializes an unserializable value without using a transformer', function (): void {
    $codec = new Codec('', []);
    $expected = new Fiber(fn() => null);

    $actual = $codec->serialize($expected);

    expect($actual)->toBeString();
});

test('serializes a unserializable array values', function (): void {
    $codec = new Codec('', []);
    $input = ['a' => 123, 'b' => (object)['d' => '456'], 'c' => new Fiber(fn() => null)];

    $actual = $codec->serialize($input);

    expect($actual)->toBeString();
});

test('serializes objects with circular reference', function (): void {
    $transformerStub = new class extends TestTransformer {
        public function transforms(mixed $value): bool
        {
            return true;
        }

        public function resolves(Stasis $value): bool
        {
            return true;
        }

        public function transform(mixed $value): Stasis
        {
            return new Stasis(Closure::class);
        }

        public function resolve(Stasis $value): mixed
        {
            return fn() => null;
        }
    };
    $codec = new Codec('', [$transformerStub]);
    $object = (object)['d' => '456'];
    $object->d = $object;
    $input = ['a' => 123, 'b' => $object, 'c' => fn() => null];

    $actual = $codec->unserialize($codec->serialize($input));

    expect($actual['b'])->toBe($actual['b']->d);
});

test('deserializes nested arrays', function (): void {
    $codec = new Codec('', []);

    $actual = $codec->unserialize('O:14:"Serializor\\Box":2:{s:5:"value";a:1:{i:0;a:1:{i:0;a:1:{i:0;a:1:{i:0;s:5:"VALUE";}}}}s:9:"shortcuts";a:0:{}}');

    expect($actual[0][0][0][0])->toBe('VALUE');
})->todo('test lines 288/289');

abstract class TestTransformer implements Transformer
{
    public function transforms(mixed $value): bool
    {
        return false;
    }

    public function resolves(Stasis $value): bool
    {
        return false;
    }

    public function transform(mixed $value): Stasis
    {
        throw new Error('`transform` is not configured');
    }

    public function resolve(Stasis $value): mixed
    {
        throw new Error('`resolve` is not configured');
    }
}
