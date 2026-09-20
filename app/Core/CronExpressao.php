<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Expressão cron de 5 campos (minuto hora dia mês dia-da-semana) para os agendamentos do worker (SPEC §11).
 * Aceita `*`, números, faixas (`1-5`), listas (`1,15`) e passos (`8-18/2`, ou `*` seguido de barra e o passo). Dia da semana 0–7 (0 e 7 = domingo).
 * Como no cron tradicional, quando dia do mês e dia da semana estão ambos restritos basta um deles combinar.
 * Sem nomes de mês/dia (`jan`, `mon`) e sem atalhos (`@daily`). Usa o fuso padrão do PHP.
 */
final class CronExpressao
{
    private const LIMITE_DIAS = 1830;

    /** @param list<int> $minutos ... */
    private function __construct(
        private readonly array $minutos,
        private readonly array $horas,
        private readonly array $dias,
        private readonly array $meses,
        private readonly array $semana,
        private readonly bool $diaRestrito,
        private readonly bool $semanaRestrita,
    ) {
    }

    /** Null se a expressão for inválida. */
    public static function analisar(string $expressao): ?self
    {
        $campos = preg_split('/\s+/', trim($expressao)) ?: [];
        if (count($campos) !== 5) {
            return null;
        }
        $minutos = self::campo($campos[0], 0, 59);
        $horas = self::campo($campos[1], 0, 23);
        $dias = self::campo($campos[2], 1, 31);
        $meses = self::campo($campos[3], 1, 12);
        $semana = self::campo($campos[4], 0, 7);
        if (in_array(null, [$minutos, $horas, $dias, $meses, $semana], true)) {
            return null;
        }
        $semana = array_values(array_unique(array_map(static fn (int $d): int => $d % 7, $semana)));
        sort($semana);
        return new self($minutos, $horas, $dias, $meses, $semana, $campos[2][0] !== '*', $campos[4][0] !== '*');
    }

    public function combina(DateTimeInterface $t): bool
    {
        return in_array((int) $t->format('i'), $this->minutos, true)
            && in_array((int) $t->format('G'), $this->horas, true)
            && $this->diaCombina($t);
    }

    /** Primeiro instante (minuto cheio) estritamente depois de $apos que combina; null se não houver em ~5 anos. */
    public function proximo(DateTimeInterface $apos): ?DateTimeImmutable
    {
        $inicio = DateTimeImmutable::createFromInterface($apos)->setTime((int) $apos->format('G'), (int) $apos->format('i'), 0)->modify('+1 minute');
        $dia = $inicio->setTime(0, 0);
        for ($i = 0; $i < self::LIMITE_DIAS; $i++, $dia = $dia->modify('+1 day')) {
            if (!$this->diaCombina($dia)) {
                continue;
            }
            foreach ($this->horas as $h) {
                foreach ($this->minutos as $m) {
                    $candidato = $dia->setTime($h, $m);
                    if ($candidato >= $inicio) {
                        return $candidato;
                    }
                }
            }
        }
        return null;
    }

    private function diaCombina(DateTimeInterface $t): bool
    {
        if (!in_array((int) $t->format('n'), $this->meses, true)) {
            return false;
        }
        $doDia = in_array((int) $t->format('j'), $this->dias, true);
        $daSemana = in_array((int) $t->format('w'), $this->semana, true);
        return $this->diaRestrito && $this->semanaRestrita ? ($doDia || $daSemana) : ($doDia && $daSemana);
    }

    /** @return list<int>|null valores ordenados e únicos, ou null se o campo for inválido */
    private static function campo(string $campo, int $min, int $max): ?array
    {
        $valores = [];
        foreach (explode(',', $campo) as $parte) {
            if (preg_match('#^(\*|(\d{1,2})(?:-(\d{1,2}))?)(?:/(\d{1,2}))?$#', $parte, $m) !== 1) {
                return null;
            }
            $passo = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 1;
            if ($passo < 1) {
                return null;
            }
            if ($m[1] === '*') {
                [$de, $ate] = [$min, $max];
            } else {
                $de = (int) $m[2];
                $ate = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (isset($m[4]) && $m[4] !== '' ? $max : $de);
            }
            if ($de < $min || $ate > $max || $de > $ate) {
                return null;
            }
            for ($v = $de; $v <= $ate; $v += $passo) {
                $valores[$v] = $v;
            }
        }
        sort($valores);
        return array_values($valores);
    }
}
