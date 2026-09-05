<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Mqtt;

use WeewxPhp\Upload\Net\Connection;
use WeewxPhp\Upload\Rejected;

/**
 * The byte layout of MQTT 3.1.1, as far as a publisher needs it: a fixed
 * header of one type byte and a variable-length integer, then the body.
 * Frozen since 2014, and what a weather station needs is a fraction of
 * it, which is why a hundred lines are enough and a library is not.
 */
final class Wire
{
    public const CONNECT = 1;
    public const CONNACK = 2;
    public const PUBLISH = 3;
    public const PUBACK = 4;
    public const PINGRESP = 13;
    public const DISCONNECT = 14;

    /** What CONNACK's second byte means, in the specification's words, because that is what somebody will search for. */
    public const CONNACK_REASONS = [
        0 => 'connection accepted',
        1 => 'unacceptable protocol version',
        2 => 'identifier rejected',
        3 => 'server unavailable',
        4 => 'bad user name or password',
        5 => 'not authorised',
    ];

    /** Codes where trying again is pointless: a wrong password does not become right by being retried. */
    public const FATAL_CONNACK = [1, 4, 5];

    private function __construct() {}

    /**
     * MQTT's variable-length integer: seven bits a byte, top bit continues.
     * Four bytes at most, so 268 435 455 is the largest packet.
     */
    public static function encodeLength(int $length): string
    {
        $out = '';
        do {
            $byte = $length % 128;
            $length = intdiv($length, 128);
            if ($length > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($length > 0);
        return $out;
    }

    /**
     * A UTF-8 string with a two-byte length in front. Every string in MQTT
     * is this shape, topic, client id and user name alike.
     *
     * @throws Rejected For a string longer than the two bytes can say.
     */
    public static function encodeString(string $text): string
    {
        if (strlen($text) > 0xFFFF) {
            throw new Rejected(sprintf('%s is too long for MQTT', substr($text, 0, 40)), permanent: true);
        }
        return pack('n', strlen($text)) . $text;
    }

    /** A whole packet: the fixed header and the body. */
    public static function packet(int $type, int $flags, string $body): string
    {
        return chr(($type << 4) | $flags) . self::encodeLength(strlen($body)) . $body;
    }

    /** CONNECT with a clean session, the credentials where given, and the keepalive in seconds. */
    public static function connect(string $clientId, string $username, string $password, int $keepalive): string
    {
        $flags = 0x02;
        $payload = self::encodeString($clientId);
        if ($username !== '') {
            $flags |= 0x80;
            $payload .= self::encodeString($username);
            if ($password !== '') {
                $flags |= 0x40;
                $payload .= self::encodeString($password);
            }
        }
        $variable = self::encodeString('MQTT') . chr(4) . chr($flags) . pack('n', $keepalive);
        return self::packet(self::CONNECT, 0, $variable . $payload);
    }

    /** PUBLISH; the packet id only counts at QoS 1. */
    public static function publish(string $topic, string $payload, int $qos, bool $retain, int $packetId): string
    {
        $body = self::encodeString($topic);
        if ($qos > 0) {
            $body .= pack('n', $packetId);
        }
        return self::packet(self::PUBLISH, ($qos << 1) | ($retain ? 1 : 0), $body . $payload);
    }

    public static function disconnect(): string
    {
        return self::packet(self::DISCONNECT, 0, '');
    }

    /**
     * The next packet off a connection as (type, flags, body).
     *
     * @return array{0: int, 1: int, 2: string}
     *
     * @throws Rejected When the connection ends or the length is malformed.
     */
    public static function read(Connection $connection): array
    {
        $first = ord($connection->read(1));
        $length = 0;
        $multiplier = 1;
        for ($i = 0; ; ++$i) {
            $byte = ord($connection->read(1));
            $length += ($byte & 0x7F) * $multiplier;
            if (($byte & 0x80) === 0) {
                break;
            }
            if ($i === 3) {
                throw new Rejected('malformed packet length from the broker');
            }
            $multiplier *= 128;
        }
        return [$first >> 4, $first & 0x0F, $length > 0 ? $connection->read($length) : ''];
    }
}
