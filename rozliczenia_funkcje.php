<?php
/**
 * Oblicza hipotetyczną kwotę do wypłaty "gdybym rozliczył dziś"
 * dla podanego dziecka.
 *
 * Założenia:
 *  - liczymy pełne tygodnie od ostatniego rozliczenia do DZISIAJ,
 *  - brutto = liczba_pełnych_tygodni * tygodniowe kieszonkowe,
 *  - potrącenia = suma nierozliczonych potrąceń,
 *  - wynik = max(0, brutto - potrącenia).
 *
 * Dopasuj nazwy tabel / kolumn do swoich, jeśli się różnią.
 */
/**
 * Oblicza hipotetyczną kwotę do wypłaty "gdybym rozliczył dziś"
 * dla danego dziecka.
 *
 * Parametry:
 *  - $mysqli              – połączenie z bazą,
 *  - $dzieckoId           – id użytkownika-dziecka,
 *  - $kieszonkoweTyg      – tygodniowe kieszonkowe tego dziecka,
 *  - $sumaNierozliczonych – suma nierozliczonych potrąceń (liczona osobno w index.php).
 */
function obliczHipotetyczneKieszonkoweNaDzis(
    mysqli $mysqli,
    int $dzieckoId,
    float $kieszonkoweTyg,
    float $sumaNierozliczonych
): float {
    // 1. Spróbuj wziąć datę końca ostatniego rozliczenia
    $lastEnd = null;
    $stmt = $mysqli->prepare('SELECT MAX(okres_do) AS last_end FROM rozliczenia WHERE dziecko_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $dzieckoId);
        $stmt->execute();
        $stmt->bind_result($lastEnd);
        $stmt->fetch();
        $stmt->close();
    }

    if ($lastEnd) {
        // Jeśli były rozliczenia – start od końca ostatniego okresu
        $dataStart = new DateTimeImmutable($lastEnd);
    } else {
        // 2. Brak rozliczeń – bierzemy najstarsze nierozliczone potrącenie
        $minZdarzenie = null;
        $stmt = $mysqli->prepare('
            SELECT MIN(data_zdarzenia) 
            FROM potracenia 
            WHERE dziecko_id = ? AND rozliczone = 0
        ');
        if ($stmt) {
            $stmt->bind_param('i', $dzieckoId);
            $stmt->execute();
            $stmt->bind_result($minZdarzenie);
            $stmt->fetch();
            $stmt->close();
        }

        if ($minZdarzenie) {
            $dataStart = new DateTimeImmutable($minZdarzenie);
        } else {
            // 3. Brak potrąceń – fallback do created_at z uzytkownicy
            $createdAt = null;
            $stmt = $mysqli->prepare('SELECT created_at FROM uzytkownicy WHERE id = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('i', $dzieckoId);
                $stmt->execute();
                $stmt->bind_result($createdAt);
                $stmt->fetch();
                $stmt->close();
            }

            if (!$createdAt) {
                return 0.0;
            }

            $dataStart = new DateTimeImmutable($createdAt);
        }
    }

    $dataDzis = new DateTimeImmutable('today');

    // Gdyby coś poszło dziwnie z datami – nie liczymy
    if ($dataStart >= $dataDzis) {
        return 0.0;
    }

    $dni = $dataStart->diff($dataDzis)->days;
    if ($dni <= 0) {
        return 0.0;
    }

    $pelneTygodnie = intdiv($dni, 7);
    if ($pelneTygodnie <= 0) {
        return 0.0;
    }

    $brutto = $pelneTygodnie * $kieszonkoweTyg;
    $netto  = $brutto - $sumaNierozliczonych;

    if ($netto < 0) {
        $netto = 0.0;
    }

    return $netto;
}
?>