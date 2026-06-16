import pathlib
root = pathlib.Path(__file__).resolve().parent.parent / "Frontend" / "nfe"
for p in root.rglob("*.php"):
    t = p.read_text(encoding="utf-8")
    n = t.replace("<motion", "<div").replace("</motion>", "</div>")
    if t != n:
        p.write_text(n, encoding="utf-8")
        print("fixed", p.relative_to(root.parent.parent))
