# (Laravel) Package Init Command

Create a (Laravel) Composer package via cli.

Features:

* Same as `composer init`
* Add different authors (name, email, homepage)
* Add licence file
* Add optional Configuration, Migrations, Routes, View and Language Files for Laravel package

## Install

```bash
composer global require norman-huth/package-init
```

And register command in [Lura](https://github.com/Muetze42/lura):

```bash
lura register norman-huth/package-init
```

## Usage

Run Lura in cli.

```bash
lura
```

---

Many parts of the code were taken from the Composer package by Nils Adermann and Jordi Boggiano.
