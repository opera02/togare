<?php

declare(strict_types=1);

namespace Espo\Modules\TogareCore\Validators;

/**
 * Validators brasileiros — CPF, CNPJ, CEP, telefone.
 *
 * Arquitetura Step 5 pede **dupla validação** (client + server). No cliente
 * usamos a lib npm `validation-br`; aqui replicamos **as mesmas regras** em
 * PHP puro, sem dependências externas.
 *
 * Storage: sempre só dígitos (sem máscara). Máscara aplicada só na UI via
 * helpers Handlebars. Os métodos abaixo aceitam input com ou sem máscara —
 * removem não-dígitos antes de validar.
 *
 * Classe final com métodos estáticos — validators são funções matemáticas
 * puras, não precisam de DI nem de state.
 */
final class BrValidator
{
    /**
     * Valida CPF (11 dígitos + DV mod 11). Aceita input com ou sem máscara.
     *
     * ✓ '12345678909', '123.456.789-09'
     * ✗ '12345678900' (DV errado), '11111111111' (todos iguais),
     *   '12345' (tamanho)
     */
    public static function isValidCpf(string $input): bool
    {
        $d = self::digitsOnly($input);
        if (\strlen($d) !== 11) {
            return false;
        }
        if (\preg_match('/^(\d)\1{10}$/', $d) === 1) {
            return false;
        }

        // Calcula os 2 dígitos verificadores em loop.
        for ($j = 9; $j <= 10; $j++) {
            $sum = 0;
            for ($i = 0; $i < $j; $i++) {
                $sum += (int) $d[$i] * ($j + 1 - $i);
            }
            $dv = ($sum * 10) % 11;
            if ($dv === 10) {
                $dv = 0;
            }
            if ($dv !== (int) $d[$j]) {
                return false;
            }
        }
        return true;
    }

    /**
     * Valida CNPJ (14 dígitos + DV mod 11 com pesos específicos).
     *
     * ✓ '11222333000181', '11.222.333/0001-81'
     * ✗ '11222333000180' (DV errado), '00000000000000' (todos iguais)
     */
    public static function isValidCnpj(string $input): bool
    {
        $d = self::digitsOnly($input);
        if (\strlen($d) !== 14) {
            return false;
        }
        if (\preg_match('/^(\d)\1{13}$/', $d) === 1) {
            return false;
        }

        $weights1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $weights2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $sum1 = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum1 += (int) $d[$i] * $weights1[$i];
        }
        $dv1 = $sum1 % 11;
        $dv1 = $dv1 < 2 ? 0 : 11 - $dv1;
        if ($dv1 !== (int) $d[12]) {
            return false;
        }

        $sum2 = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum2 += (int) $d[$i] * $weights2[$i];
        }
        $dv2 = $sum2 % 11;
        $dv2 = $dv2 < 2 ? 0 : 11 - $dv2;
        return $dv2 === (int) $d[13];
    }

    /**
     * Valida CEP (exatamente 8 dígitos). Sem DV — é formato.
     *
     * ✓ '01310100', '01310-100'
     * ✗ '0131010' (7 dígitos), '01310-1000' (9 após remover máscara)
     *
     * Nota: validação de existência real (ViaCEP/correios) é adapter
     * externo, fora do escopo desta classe.
     */
    public static function isValidCep(string $input): bool
    {
        return \strlen(self::digitsOnly($input)) === 8;
    }

    /**
     * Valida telefone brasileiro — fixo (10 dígitos) ou celular (11 dígitos
     * com nono dígito obrigatório).
     *
     * DDD deve estar entre 11 e 99.
     *
     * ✓ '1138001234' (fixo SP), '11987654321' (celular SP)
     * ✗ '1038001234' (DDD 10 inválido),
     *   '11887654321' (celular sem nono 9),
     *   '113800123' (tamanho)
     */
    public static function isValidPhone(string $input): bool
    {
        $d = self::digitsOnly($input);
        $len = \strlen($d);
        if ($len !== 10 && $len !== 11) {
            return false;
        }

        $ddd = (int) \substr($d, 0, 2);
        if ($ddd < 11 || $ddd > 99) {
            return false;
        }

        // Celular BR moderno: 11 dígitos com o 3º = 9.
        if ($len === 11 && $d[2] !== '9') {
            return false;
        }

        return true;
    }

    /**
     * Remove não-dígitos de um input. Helper público — útil para caller
     * que quer armazenar storage format antes de persistir.
     */
    public static function digitsOnly(string $input): string
    {
        return (string) \preg_replace('/\D+/', '', $input);
    }
}
