# Balloon Client for Receipts Printer

## Install

```bash
apt update
apt install php php-curl composer
composer install
```

## Running

Add credentials for API to `~/.netrc` like `default login admin password adm1n`.

Run the script like:

```bash
php main.php http://localhost/domjudge
```

## Testing

You can test if your ticket printer works by `echo "Hello World" > /dev/usb/lp0`, if it works then it will print `Hello World` on the ticket printer.
