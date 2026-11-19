# jBBCode 1.4.2, vendored

The core of `jbbcode/jbbcode` v1.4.2 (commit
`d9a132e7886a11cf997e3ec025a41bdf97899704` of
`https://github.com/jbowens/jBBCode`), taken into the repository on 2026-11-19:
the 19 PHP files outside `tests/` and `examples/`, and the licence. MIT,
Copyright (c) 2011 Jackson Owens — see `LICENSE.md`.

## Why it is here

The library tokenises `[tag]…[/tag]` into a node tree for the posting parser
(`plugins/BbcodeParser`). Its last release is from June 2020 and nobody
maintains it. It does no network access, no file access and no evaluation,
every tag Saito understands is defined in Saito, and it has never needed a
fix — so it stays (issue #98). What changes is who can fix it: a defect, or a
PHP version that turns one of its deprecations into an error, now gets fixed
in this directory instead of waiting for an upstream that is not there.

## What is ours in here

The files were copied unchanged. Every change since is in the git history of
this directory, and there should be few: this is a parser for hostile input,
and its behaviour is pinned by `plugins/BbcodeParser/tests`. Do not modernise
it for its own sake.

## How it is loaded

`composer.json` maps the namespace `JBBCode\` to this directory (PSR-4). The
`require_once` lines inside the files are the library's own and still work.
The directory lies outside `src/` and `plugins/` on purpose, so the code
style check, PHPStan and Psalm do not hold code written from 2011 on to this
project's rules.
