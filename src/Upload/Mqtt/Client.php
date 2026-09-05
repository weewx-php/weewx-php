<?php

declare(strict_types=1);

namespace WeewxPhp\Upload\Mqtt;

use WeewxPhp\Upload\Net\Connection;
use WeewxPhp\Upload\Net\SocketFactory;
use WeewxPhp\Upload\Rejected;

/**
 * One connection to a broker, for publishing: connect, publish, say
 * goodbye. The subset of MQTT 3.1.1 a station needs, over a socket, with
 * nothing installed. What is deliberately absent: QoS 2, four packets to
 * deliver a temperature exactly once that is superseded in five minutes;
 * session resumption, because what would be resumed is in the archive;
 * subscriptions and pings, because the connection lives one tick.
 *
 * The one pitfall worth a sentence: a QoS 1 publish has to wait for its
 * own PUBACK. A client that takes the next incoming packet for it works
 * until a broker sends something else in between.
 */
final class Client
{
    public const DEFAULT_PORT = 1883;
    public const DEFAULT_TLS_PORT = 8883;

    /** How many packets are read past while waiting for one PUBACK before a broker is called unresponsive. */
    private const PATIENCE = 32;

    private ?Connection $connection = null;

    private int $packetId = 0;

    /**
     * @param string $clientId Unique on the broker: two clients sharing a name take turns
     *     kicking each other off, and it looks like a flapping network.
     * @param int $keepalive Seconds the broker waits for a sign of life; irrelevant while the
     *     connection lives one tick, but part of CONNECT.
     */
    public function __construct(
        private readonly SocketFactory $sockets,
        private readonly string $host,
        private readonly int $port,
        private readonly string $clientId,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $tls,
        private readonly bool $verify,
        private readonly int $keepalive,
        private readonly int $timeout,
    ) {}

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function connected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Open the connection and log in.
     *
     * @throws Rejected Permanent when the broker refuses the credentials or the protocol.
     */
    public function connect(): void
    {
        $this->close();
        $connection = $this->sockets->open($this->host, $this->port, $this->tls, $this->verify, $this->timeout);
        try {
            $connection->write(Wire::connect($this->clientId, $this->username, $this->password, $this->keepalive));
            [$type, , $body] = Wire::read($connection);
            if ($type !== Wire::CONNACK || strlen($body) < 2) {
                throw new Rejected(sprintf('%s answered CONNECT with packet type %d, not CONNACK', $this->host, $type));
            }
            $code = ord($body[1]);
            if ($code !== 0) {
                throw new Rejected(
                    sprintf('%s refused the connection: %s', $this->host, Wire::CONNACK_REASONS[$code] ?? sprintf('code %d', $code)),
                    permanent: in_array($code, Wire::FATAL_CONNACK, true),
                );
            }
        } catch (Rejected $error) {
            $connection->close();
            throw $error;
        }
        $this->connection = $connection;
    }

    /**
     * Send one message, connecting first if need be. `retain` is what
     * makes a broker hand the last value to a browser that has just loaded
     * the page.
     *
     * @throws Rejected
     */
    public function publish(string $topic, string $payload, int $qos = 0, bool $retain = false): void
    {
        if ($qos < 0 || $qos > 1) {
            throw new Rejected('only QoS 0 and 1 are implemented', permanent: true);
        }
        if ($this->connection === null) {
            $this->connect();
        }
        $connection = $this->connection;
        if ($connection === null) {
            throw new Rejected('not connected');
        }
        $packetId = 0;
        if ($qos === 1) {
            // Packet ids run 1..65535; zero is not allowed.
            $this->packetId = $this->packetId % 65535 + 1;
            $packetId = $this->packetId;
        }
        try {
            $connection->write(Wire::publish($topic, $payload, $qos, $retain, $packetId));
            if ($qos === 1) {
                $this->awaitPuback($connection, $packetId);
            }
        } catch (Rejected $error) {
            $this->close();
            throw $error;
        }
    }

    /** Wait for the broker to acknowledge this packet, reading past anything else it sends. */
    private function awaitPuback(Connection $connection, int $packetId): void
    {
        for ($i = 0; $i < self::PATIENCE; ++$i) {
            [$type, , $body] = Wire::read($connection);
            if ($type === Wire::PUBACK && strlen($body) >= 2) {
                $acknowledged = unpack('n', substr($body, 0, 2));
                if (is_array($acknowledged) && ($acknowledged[1] ?? null) === $packetId) {
                    return;
                }
            }
        }
        throw new Rejected(sprintf('%s did not acknowledge a QoS 1 message', $this->host));
    }

    /** Say goodbye if possible, then drop the socket either way. */
    public function close(): void
    {
        $connection = $this->connection;
        $this->connection = null;
        if ($connection === null) {
            return;
        }
        try {
            $connection->write(Wire::disconnect());
        } catch (Rejected) {
            // Already gone: the ordinary case here, and not worth a line in anybody's log.
        }
        $connection->close();
    }
}
