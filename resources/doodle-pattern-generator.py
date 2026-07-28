"""Generate a seamless Fringe-themed doodle tile matching the site's election pattern.

Style notes taken from /assets/yegvote-2025/endorsement-bg.png:
  background #00777c, stroke #2ea4ab, ~7px stroke, round caps/joins, no fill,
  large outline doodles with small dashes/sparkles scattered between them.

Seamlessness: every doodle is drawn into one group, and that group is <use>d at all
nine (dx, dy) offsets. Anything crossing an edge reappears on the opposite side.
"""
import math, random

W = 1024
SEED = 20260728
random.seed(SEED)

# ---------------------------------------------------------------- path helpers

def jitter(pts, amt=1.8):
    return [(x + random.uniform(-amt, amt), y + random.uniform(-amt, amt)) for x, y in pts]


def smooth(pts, closed=False, wobble=1.8):
    """Catmull-Rom through points -> cubic bezier, with hand-drawn jitter."""
    p = jitter(pts, wobble)
    n = len(p)
    if n < 2:
        return ""
    if closed:
        seq = list(range(n)) + [0]
    else:
        seq = list(range(n))
    d = f"M{p[seq[0]][0]:.1f},{p[seq[0]][1]:.1f}"
    for i in range(len(seq) - 1):
        i0 = seq[i - 1] if i > 0 else (seq[-2] if closed else seq[0])
        i1, i2 = seq[i], seq[i + 1]
        i3 = seq[i + 2] if i + 2 < len(seq) else (seq[1] if closed else seq[-1])
        p0, p1, p2, p3 = p[i0], p[i1], p[i2], p[i3]
        c1 = (p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6)
        c2 = (p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6)
        d += f"C{c1[0]:.1f},{c1[1]:.1f} {c2[0]:.1f},{c2[1]:.1f} {p2[0]:.1f},{p2[1]:.1f}"
    if closed:
        d += "Z"
    return d


def arc_pts(cx, cy, rx, ry, a0, a1, n=10):
    return [(cx + rx * math.cos(math.radians(a)), cy + ry * math.sin(math.radians(a)))
            for a in [a0 + (a1 - a0) * i / (n - 1) for i in range(n)]]


def ellipse(cx, cy, rx, ry, wobble=2.0):
    return smooth(arc_pts(cx, cy, rx, ry, 0, 360 - 360 / 14, 14), closed=True, wobble=wobble)


# ---------------------------------------------------------------- the doodles
# Each returns a list of path `d` strings in a local ~100x100 box centred on (50,50).

def d_star():
    pts = []
    for i in range(10):
        a = math.radians(-90 + i * 36)
        r = 46 if i % 2 == 0 else 19
        pts.append((50 + r * math.cos(a), 50 + r * math.sin(a)))
    return [smooth(pts, closed=True, wobble=2.2)]


def d_ticket():
    body = [(8, 26), (40, 24), (72, 25), (92, 27), (93, 50), (92, 74),
            (60, 76), (28, 75), (8, 73), (9, 50)]
    out = [smooth(body, closed=True, wobble=1.6)]
    # perforation line
    out.append(smooth([(70, 27), (69, 40), (70, 60), (69, 73)], wobble=1.4))
    # little star on the stub
    for p in [d_star()]:
        pass
    out.append(smooth([(31, 42), (37, 42), (32, 47), (34, 54), (28, 50),
                       (22, 54), (24, 47), (19, 42), (25, 42), (28, 36)],
                      closed=True, wobble=1.2))
    return out


def d_mask(smile=True):
    face = [(50, 12), (74, 20), (84, 42), (80, 66), (64, 84), (50, 90),
            (36, 84), (20, 66), (16, 42), (26, 20)]
    out = [smooth(face, closed=True, wobble=2.0)]
    # eyes
    out.append(smooth(arc_pts(35, 45, 9, 6, 200, 340, 7), wobble=1.2))
    out.append(smooth(arc_pts(65, 45, 9, 6, 200, 340, 7), wobble=1.2))
    # mouth
    if smile:
        out.append(smooth(arc_pts(50, 58, 20, 13, 20, 160, 9), wobble=1.4))
    else:
        out.append(smooth(arc_pts(50, 76, 20, 13, 200, 340, 9), wobble=1.4))
    return out


def d_spotlight():
    # lamp can, tilted, with the beam opening downward and left
    out = [smooth([(46, 6), (78, 14), (72, 40), (40, 32)], closed=True, wobble=1.6)]
    out.append(smooth([(43, 33), (30, 60), (14, 92)], wobble=1.8))   # beam edge
    out.append(smooth([(70, 41), (68, 68), (64, 96)], wobble=1.8))   # beam edge
    out.append(smooth([(16, 92), (40, 98), (64, 96)], wobble=1.6))   # pool of light
    out.append(smooth([(82, 22), (94, 18)], wobble=1.0))             # ray
    out.append(smooth([(80, 36), (92, 40)], wobble=1.0))             # ray
    return out


def d_rating():
    """Three little stars in a row. It is a review site, after all."""
    out = []
    for i, cx in enumerate((20, 50, 80)):
        pts = []
        for k in range(10):
            a = math.radians(-90 + k * 36)
            r = 15 if k % 2 == 0 else 6.5
            pts.append((cx + r * math.cos(a), 50 + r * math.sin(a)))
        out.append(smooth(pts, closed=True, wobble=1.1))
    return out


def d_balloon():
    out = [smooth(arc_pts(50, 40, 28, 33, 0, 350, 12), closed=True, wobble=1.3)]
    out.append(smooth([(44, 72), (50, 78), (56, 72)], closed=True, wobble=1.0))
    out.append(smooth([(50, 78), (44, 90), (54, 98)], wobble=1.6))
    return out


def d_program():
    """Folded programme / playbill."""
    out = [smooth([(18, 16), (50, 24), (82, 16), (84, 80), (50, 88), (16, 80)],
                  closed=True, wobble=1.8)]
    out.append(smooth([(50, 24), (50, 88)], wobble=1.4))
    out.append(smooth([(26, 40), (42, 44)], wobble=1.0))
    out.append(smooth([(26, 54), (42, 58)], wobble=1.0))
    out.append(smooth([(58, 44), (74, 40)], wobble=1.0))
    return out


def d_curtain():
    out = [smooth([(6, 14), (50, 8), (94, 14)], wobble=1.6)]           # rail
    out.append(smooth([(14, 16), (10, 50), (16, 84), (34, 88),
                       (30, 52), (32, 18)], closed=True, wobble=2.0))  # left drape
    out.append(smooth([(86, 16), (90, 50), (84, 84), (66, 88),
                       (70, 52), (68, 18)], closed=True, wobble=2.0))  # right drape
    out.append(smooth([(34, 20), (50, 34), (66, 20)], wobble=1.4))     # swag
    return out


def d_mic():
    out = [smooth([(38, 12), (62, 12), (64, 40), (50, 50), (36, 40)],
                  closed=True, wobble=1.6)]
    out.append(smooth([(39, 24), (61, 24)], wobble=1.0))
    out.append(smooth([(39, 32), (61, 32)], wobble=1.0))
    out.append(smooth([(50, 50), (50, 76)], wobble=1.4))          # stem
    out.append(smooth([(32, 84), (50, 78), (68, 84)], wobble=1.6))  # base
    return out


def d_cup():
    out = [smooth([(26, 22), (74, 22), (66, 84), (34, 84)], closed=True, wobble=1.8)]
    out.append(smooth([(28, 34), (50, 30), (72, 34)], wobble=1.4))   # foam line
    out.append(smooth(arc_pts(42, 15, 10, 8, 0, 350, 9), closed=True, wobble=1.4))
    out.append(smooth(arc_pts(62, 13, 7, 6, 0, 350, 8), closed=True, wobble=1.2))
    return out


def d_note():
    out = [smooth(arc_pts(34, 74, 16, 12, 0, 350, 10), closed=True, wobble=1.6)]
    out.append(smooth([(50, 74), (52, 40), (51, 16)], wobble=1.4))
    out.append(smooth([(51, 18), (70, 26), (72, 44), (62, 50)], wobble=1.8))
    return out


def d_hat():
    out = [smooth([(32, 22), (68, 20), (70, 62), (30, 64)], closed=True, wobble=1.8)]
    out.append(smooth([(14, 66), (50, 58), (86, 66), (50, 78)], closed=True, wobble=2.0))
    out.append(smooth([(31, 50), (69, 48)], wobble=1.2))
    return out


def d_club():
    out = [smooth([(50, 8), (60, 26), (62, 56), (56, 80), (44, 80),
                   (38, 56), (40, 26)], closed=True, wobble=1.8)]
    out.append(smooth(arc_pts(50, 86, 11, 9, 0, 350, 9), closed=True, wobble=1.4))
    out.append(smooth([(41, 34), (59, 33)], wobble=1.0))
    return out


def d_bubble():
    out = [smooth([(16, 20), (52, 12), (86, 22), (90, 48), (74, 68),
                   (40, 70), (14, 58), (10, 36)], closed=True, wobble=2.2)]
    out.append(smooth([(38, 68), (30, 88), (52, 70)], wobble=1.6))
    out.append(smooth([(50, 30), (49, 46)], wobble=1.0))
    out.append(smooth([(49, 54), (49, 57)], wobble=0.6))
    return out


def d_heart():
    return [smooth([(50, 82), (30, 66), (18, 50), (18, 33), (33, 23), (50, 37),
                    (67, 23), (82, 33), (82, 50), (70, 66)], closed=True, wobble=1.2)]


def d_stage():
    """Proscenium arch / little stage."""
    out = [smooth([(10, 78), (50, 70), (90, 78)], wobble=1.6)]
    out.append(smooth([(18, 78), (20, 30), (50, 18), (80, 30), (82, 78)],
                      wobble=2.0))
    out.append(smooth([(34, 74), (36, 40)], wobble=1.2))
    out.append(smooth([(66, 74), (64, 40)], wobble=1.2))
    return out


def d_sparkle():
    out = [smooth([(50, 14), (54, 44), (86, 50), (54, 56), (50, 86),
                   (46, 56), (14, 50), (46, 44)], closed=True, wobble=1.6)]
    return out


# ---------------------------------------------------------------- layout
# Doodles sit on a jittered 5x5 grid so density stays even the way the original's
# does, at roughly the original's size (~160-200px across the 1024 tile).
TRAGIC = lambda: d_mask(False)

GRID = [
    d_mask,    d_ticket,  d_star,    d_curtain, d_balloon,
    d_note,    d_cup,     d_bubble,  TRAGIC,    d_heart,
    d_program, d_rating,  d_ticket,  d_hat,     d_star,
    d_star,    d_curtain, d_mask,    d_program, d_note,
    d_cup,     d_hat,     d_balloon, d_rating,  TRAGIC,
]

PLACEMENT = []
_cell = W / 5
for idx, fn in enumerate(GRID):
    gx, gy = idx % 5, idx // 5
    cx = (gx + 0.5) * _cell + random.uniform(-46, 46)
    cy = (gy + 0.5) * _cell + random.uniform(-46, 46)
    rot = random.uniform(-7, 7) if fn is d_rating else random.uniform(-20, 20)
    PLACEMENT.append((fn, cx, cy, random.uniform(1.35, 1.75), rot))

# small accent marks: short dashes, ticks, tiny squiggles
ACCENTS = []
for _ in range(46):
    x, y = random.uniform(0, W), random.uniform(0, W)
    kind = random.choice(['dash', 'dash', 'tick', 'wave', 'dot'])
    a = random.uniform(0, 360)
    if kind == 'dash':
        L = random.uniform(14, 26)
        ACCENTS.append(smooth([(x, y), (x + L * math.cos(math.radians(a)),
                                        y + L * math.sin(math.radians(a)))], wobble=1.2))
    elif kind == 'tick':
        ACCENTS.append(smooth([(x, y), (x + 9, y + 11), (x + 26, y - 14)], wobble=1.4))
    elif kind == 'wave':
        ACCENTS.append(smooth([(x, y), (x + 12, y - 10), (x + 24, y + 8),
                               (x + 36, y - 6)], wobble=1.4))
    else:
        ACCENTS.append(smooth(arc_pts(x, y, 5, 4.5, 0, 350, 8), closed=True, wobble=0.8))

# ---------------------------------------------------------------- emit
parts = []
for fn, cx, cy, sc, rot in PLACEMENT:
    ds = fn()
    parts.append(f'<g transform="translate({cx},{cy}) rotate({rot}) scale({sc:.2f}) translate(-50,-50)">')
    for d in ds:
        parts.append(f'<path d="{d}"/>')
    parts.append('</g>')
for d in ACCENTS:
    parts.append(f'<path d="{d}"/>')

body = "\n".join(parts)

uses = "\n".join(
    f'<use href="#t" x="{dx}" y="{dy}"/>'
    for dx in (-W, 0, W) for dy in (-W, 0, W)
)

svg = f'''<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {W}" width="{W}" height="{W}">
<rect width="{W}" height="{W}" fill="#00777c"/>
<defs>
<g id="t" fill="none" stroke="#2ea4ab" stroke-width="7" stroke-linecap="round" stroke-linejoin="round">
{body}
</g>
</defs>
<g clip-path="url(#c)">
{uses}
</g>
<clipPath id="c"><rect width="{W}" height="{W}"/></clipPath>
</svg>
'''

out = '/Users/troy/dev/tpavlek-me-statamic/public/assets/fringe/fringe-doodles.svg'
open(out, 'w').write(svg)
print(f'wrote {out}  ({len(svg)/1024:.1f} KB)')
