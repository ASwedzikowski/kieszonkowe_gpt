-- phpMyAdmin SQL Dump
-- version 5.2.1deb1+deb12u1
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Lis 24, 2025 at 02:55 PM
-- Wersja serwera: 10.11.14-MariaDB-0+deb12u2-log
-- Wersja PHP: 8.2.29

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

--
-- Database: `kieszonkowe`
--

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `potracenia`
--

DROP TABLE IF EXISTS `potracenia`;
CREATE TABLE IF NOT EXISTS `potracenia` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dziecko_id` bigint(20) UNSIGNED NOT NULL,
  `typ_id` smallint(5) UNSIGNED NOT NULL,
  `kwota` decimal(8,2) NOT NULL,
  `opis` varchar(255) DEFAULT NULL,
  `data_zdarzenia` date NOT NULL,
  `utworzone_at` datetime NOT NULL DEFAULT current_timestamp(),
  `utworzyl_id` bigint(20) UNSIGNED NOT NULL,
  `rozliczone` tinyint(1) NOT NULL DEFAULT 0,
  `rozliczenie_id` bigint(20) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_potracenia_dziecko` (`dziecko_id`),
  KEY `fk_potracenia_typ` (`typ_id`),
  KEY `fk_potracenia_utworzyl` (`utworzyl_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `rozliczenia`
--

DROP TABLE IF EXISTS `rozliczenia`;
CREATE TABLE IF NOT EXISTS `rozliczenia` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `dziecko_id` bigint(20) UNSIGNED NOT NULL,
  `okres_od` date NOT NULL,
  `okres_do` date NOT NULL,
  `kieszonkowe_brutto` decimal(8,2) NOT NULL,
  `suma_potracen` decimal(8,2) NOT NULL,
  `kieszonkowe_netto` decimal(8,2) NOT NULL,
  `data_rozliczenia` datetime NOT NULL DEFAULT current_timestamp(),
  `rozliczyl_id` bigint(20) UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_rozliczenie_dziecko_okres` (`dziecko_id`,`okres_od`,`okres_do`),
  KEY `fk_rozliczenia_rozliczyl` (`rozliczyl_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `typy_potracen`
--

DROP TABLE IF EXISTS `typy_potracen`;
CREATE TABLE IF NOT EXISTS `typy_potracen` (
  `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT,
  `nazwa` varchar(100) NOT NULL,
  `domyslna_kwota` decimal(8,2) NOT NULL,
  `opis` varchar(255) DEFAULT NULL,
  `aktywny` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `typy_potracen`
--

INSERT INTO `typy_potracen` (`id`, `nazwa`, `domyslna_kwota`, `opis`, `aktywny`) VALUES
(1, 'Kuwety', 5.00, 'Ocena niedostateczna z dowolnego przedmiotu', 1),
(2, 'Łazienka biała', 5.00, 'Nie wykonano umówionych obowiązków', 1),
(3, 'Kłótnia z rodzeństwem', 2.00, 'Poważna kłótnia, brak przeprosin', 1),
(4, 'Spóźnienie do szkoły', 1.50, 'Bez sensownego usprawiedliwienia', 1);

-- --------------------------------------------------------

--
-- Struktura tabeli dla tabeli `uzytkownicy`
--

DROP TABLE IF EXISTS `uzytkownicy`;
CREATE TABLE IF NOT EXISTS `uzytkownicy` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `login` varchar(50) NOT NULL,
  `haslo_hash` varchar(255) NOT NULL,
  `imie` varchar(100) NOT NULL,
  `rola` enum('rodzic','dziecko') NOT NULL DEFAULT 'dziecko',
  `rodzic_id` bigint(20) UNSIGNED DEFAULT NULL,
  `kieszonkowe_tygodniowe` decimal(8,2) DEFAULT NULL,
  `aktywny` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `login` (`login`),
  KEY `fk_uzytkownicy_rodzic` (`rodzic_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `uzytkownicy`
--

INSERT INTO `uzytkownicy` (`id`, `login`, `haslo_hash`, `imie`, `rola`, `rodzic_id`, `kieszonkowe_tygodniowe`, `aktywny`, `created_at`) VALUES
(1, 'artur', '$2y$10$aMuESylvATWN3EawwKmS2ubfGI0nHvQAluBX7f5.QFcYIf6DHKQa6', 'Artur', 'rodzic', NULL, NULL, 1, '2025-11-24 11:14:48'),
(2, 'krzys', '$2y$10$1E9IzxbhcuHACZwyNieSn.d/KgKjBMgtoS6f7Sl6A0OXBnH7vPuvi', 'Krzysztof', 'dziecko', 1, 30.00, 1, '2025-11-24 12:38:49'),
(3, 'ania', '$2y$10$n2pW/zbdXC0YOR8yY/DdJOR/jclGzz4vNQuWnfKYAqN/8Wkus6nB2', 'Anna', 'dziecko', 1, 50.00, 1, '2025-11-24 12:39:27'),
(4, 'kuba', '$2y$10$oKuVGw/xf2Sh4zgcWl0ET.p780kPNRNO3uXeO48WDLvI0NYR1Qt86', 'Jakub', 'dziecko', 1, 50.00, 1, '2025-11-24 12:39:49');

--
-- Constraints for dumped tables
--

--
-- Constraints for table `potracenia`
--
ALTER TABLE `potracenia`
  ADD CONSTRAINT `fk_potracenia_dziecko` FOREIGN KEY (`dziecko_id`) REFERENCES `uzytkownicy` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_potracenia_typ` FOREIGN KEY (`typ_id`) REFERENCES `typy_potracen` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_potracenia_utworzyl` FOREIGN KEY (`utworzyl_id`) REFERENCES `uzytkownicy` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `rozliczenia`
--
ALTER TABLE `rozliczenia`
  ADD CONSTRAINT `fk_rozliczenia_dziecko` FOREIGN KEY (`dziecko_id`) REFERENCES `uzytkownicy` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rozliczenia_rozliczyl` FOREIGN KEY (`rozliczyl_id`) REFERENCES `uzytkownicy` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `uzytkownicy`
--
ALTER TABLE `uzytkownicy`
  ADD CONSTRAINT `fk_uzytkownicy_rodzic` FOREIGN KEY (`rodzic_id`) REFERENCES `uzytkownicy` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;
