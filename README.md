# KeywordRush Agent Skills

Agent skills for KeywordRush WordPress plugins, in the open Agent Skills
format.

| Skill | Plugin | Status |
|---|---|---|
| [content-egg](skills/content-egg/SKILL.md) | [Content Egg](https://www.keywordrush.com/contentegg) | ✅ |
| affiliate-egg | Affiliate Egg | planned |
| too-much-niche | Too Much Niche | planned |

## Install

    npx skills add keywordr/agent-skills --skill content-egg

Each skill expects the corresponding plugin's agent API to be enabled
(Content Egg: Settings → Agent Access) and a WordPress application password.

## Checks

`php scripts/check-skill.php <path-to-content-egg>` verifies that every
`content-egg/<name>` in `skills/content-egg/SKILL.md` is a real ability (or an
allowed route or block type) and that every topic in its topic table has a file
under `application/abilities/guide/topics/` in that Content Egg checkout. It
exits 1 and lists the drift, 0 when clean. Run it before releasing a skill or a
Content Egg build. An optional second argument checks another `SKILL.md`.
