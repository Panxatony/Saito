#!/usr/bin/env python3
"""Tell whether two builds of the same stylesheet behave the same.

    python3 -I scripts/css-equivalence.py BEFORE.css AFTER.css [SOURCE.css]

Meant for dependency updates of the CSS toolchain (cssnano, postcss, sass): the
minified output changes, a byte diff of a one-line file is unreadable, and the
question is not *whether* it changed but whether any element now gets a
different value.

Two checks, both on "atoms" — one (at-rule context, selector, declaration)
triple per selector in a selector list:

1. **Same declarations.** The multisets of atoms must be equal. A difference
   here means something was added, dropped or rewritten, and is printed.
2. **Same order per property.** For every property, the sequence in which
   (context, selector) pairs declare it must be equal. Regrouping rules is
   harmless; moving one declaration past another for the same property can
   change the cascade, so every such swap is listed.

With SOURCE (the unminified sass output, i.e. ``yarn css`` before
``yarn css:postcss``) each swapped pair is also checked against source order,
which says which of the two builds follows the stylesheet as written.

The parser is deliberately simple — brace matching, comments stripped,
selector lists split on top-level commas. Good enough for compiler output; not
a general CSS parser.

Exit status: 0 equivalent, 1 differences found.
"""

import collections
import itertools
import re
import sys


def atoms(path):
    css = open(path, encoding="utf-8").read()
    css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
    stack, out = [], []
    for match in re.finditer(r"([^{}]*)([{}])", css):
        pre, brace = match.group(1).strip(), match.group(2)
        if brace == "{":
            stack.append(re.sub(r"\s+", " ", pre))
            continue
        if pre and stack and not stack[-1].startswith("@"):
            ctx = tuple(stack[:-1])
            decls = [d.strip() for d in re.split(r";(?![^(]*\))", pre) if d.strip()]
            for sel in re.split(r",(?![^(]*\))", stack[-1]):
                sel = re.sub(r"\s*([>~+])\s*", r"\1", sel.strip())
                for decl in decls:
                    prop = decl.split(":", 1)[0].strip()
                    out.append((prop, ctx, sel, decl))
        if stack:
            stack.pop()
    return out


def order_by_property(items):
    by = collections.defaultdict(list)
    for prop, ctx, sel, _ in items:
        key = (ctx, sel)
        if key not in by[prop]:
            by[prop].append(key)
    return by


def main(argv):
    if len(argv) not in (3, 4):
        print(__doc__.strip().splitlines()[2], file=sys.stderr)
        return 2
    before, after = atoms(argv[1]), atoms(argv[2])
    source = order_by_property(atoms(argv[3])) if len(argv) == 4 else None
    status = 0

    b_set = collections.Counter(a[1:] for a in before)
    a_set = collections.Counter(a[1:] for a in after)
    only_b, only_a = b_set - a_set, a_set - b_set
    print(f"declarations: {sum(b_set.values())} -> {sum(a_set.values())}, "
          f"only before {sum(only_b.values())}, only after {sum(only_a.values())}")
    for atom in list(only_b)[:20]:
        print("  -", atom)
    for atom in list(only_a)[:20]:
        print("  +", atom)
    if only_b or only_a:
        status = 1

    b_ord, a_ord = order_by_property(before), order_by_property(after)
    swaps = collections.Counter()
    for prop in sorted(set(b_ord) | set(a_ord)):
        pb = {k: i for i, k in enumerate(b_ord.get(prop, []))}
        pa = {k: i for i, k in enumerate(a_ord.get(prop, []))}
        common = [k for k in pb if k in pa]
        for x, y in itertools.combinations(common, 2):
            if (pb[x] < pb[y]) == (pa[x] < pa[y]):
                continue
            status = 1
            verdict = ""
            if source is not None:
                ps = {k: i for i, k in enumerate(source.get(prop, []))}
                if x in ps and y in ps:
                    after_matches = (pa[x] < pa[y]) == (ps[x] < ps[y])
                    verdict = "after follows source" if after_matches else "BEFORE follows source"
                    swaps[verdict] += 1
            print(f"  order swapped: {prop}: {x[1]}  <->  {y[1]}  {verdict}")
    if swaps:
        print("swapped pairs vs source:", dict(swaps))
    print("equivalent" if status == 0 else "differences found")
    return status


if __name__ == "__main__":
    sys.exit(main(sys.argv))
