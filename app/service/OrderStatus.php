<?php
namespace App\Service;

/** Status canônicos da operação, com leitura compatível com ordens antigas. */
final class OrderStatus
{
    public const SCHEDULED = 'Agendado';
    public const WAITING = 'Aguardando';
    public const IN_SERVICE = 'Em serviço';
    public const FINISHED = 'Finalizado';
    public const CANCELED = 'Cancelado';

    private const LEGACY = [
        'Pendente' => self::SCHEDULED,
        'Em Andamento' => self::IN_SERVICE,
        'Concluído' => self::FINISHED,
    ];

    private function __construct() {}

    public static function all(): array
    {
        return [self::SCHEDULED, self::WAITING, self::IN_SERVICE, self::FINISHED, self::CANCELED];
    }

    public static function normalize(string $status): string
    {
        return self::LEGACY[$status] ?? $status;
    }

    public static function storedValues(string $status): array
    {
        $status = self::normalize($status);
        $legacy = array_keys(array_filter(self::LEGACY, static fn($canonical) => $canonical === $status));
        return array_merge([$status], $legacy);
    }

    public static function badgeClass(string $status): string
    {
        return match (self::normalize($status)) {
            self::WAITING => 'badge-aguardando',
            self::IN_SERVICE => 'badge-em-servico',
            self::FINISHED => 'badge-finalizado',
            self::CANCELED => 'badge-cancelado',
            default => 'badge-agendado',
        };
    }
}
