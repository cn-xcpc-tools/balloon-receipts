# Balloon Client for Receipts Printer

## Install

```bash
$ composer install
```

Such a piece of SQL should be executed first.

```sql
ALTER TABLE balloon ADD COLUMN printed tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT 'Has been printed?';
```
