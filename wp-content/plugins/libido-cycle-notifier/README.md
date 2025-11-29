# Libido Cycle Notifier

To jest wtyczka WordPress. Głównym plikiem startowym jest [`libido-cycle-notifier.php`](./libido-cycle-notifier.php) z nagłówkiem wtyczki. 

Plik [`my.php`](./my.php) pozostaje jako samodzielny skrypt do ręcznego uruchamiania (np. przez CRON poza WordPressem). Korzysta z tych samych danych (`period_history.json`), ale nie jest ładowany przez WordPressa – w razie potrzeby możesz go usunąć albo dostosować pod własne scenariusze.

`period_history.json` przechowuje historię dat rozpoczęcia cyklu. WordPress zapisuje swoje opcje w bazie, a ten plik służy wyłącznie wspomnianemu skryptowi standalone.
