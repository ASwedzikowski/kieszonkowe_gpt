-- 1. (opcjonalnie) reset pól w potracenia
UPDATE potracenia
SET rozliczone = 0,
    rozliczenie_id = NULL;

-- 2. usunięcie wszystkich odpisów i rozliczeń
DELETE FROM potracenia;
DELETE FROM rozliczenia;

-- 3. (opcjonalnie) reset AUTO_INCREMENT
ALTER TABLE potracenia AUTO_INCREMENT = 1;
ALTER TABLE rozliczenia AUTO_INCREMENT = 1;
