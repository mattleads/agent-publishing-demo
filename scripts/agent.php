#!/usr/bin/env php
<?php
/**
 * Plays an AI agent and an editor against the running demo.
 *
 *   php scripts/agent.php http://localhost:8766 apb_AGENT_KEY apb_EDITOR_KEY
 *
 * The agent drafts an article, lints it, fixes what the report says, submits it. The editor approves. Then the script
 * views the page a few times from different "referrers" and prints the stats the bundle collected.
 */
declare(strict_types=1);

[$base, $agentKey, $editorKey] = [rtrim($argv[1] ?? 'http://localhost:8766', '/'), $argv[2] ?? exit("agent key missing\n"), $argv[3] ?? exit("editor key missing\n")];
$api = $base . '/api/publisher/v1';

function call(string $method, string $url, ?string $key = null, ?array $body = null, array $headers = []): array
{
    $h = array_merge(['Accept: application/json', 'Content-Type: application/json'], $headers);
    if ($key) {
        $h[] = 'Authorization: Bearer ' . $key;
    }
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'content' => $body ? json_encode($body) : '', 'ignore_errors' => true]]);
    $raw = (string) file_get_contents($url, false, $ctx);
    preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m);
    $status = (int) ($m[1] ?? 0);
    $data = json_decode($raw, true);

    return [$status, is_array($data) ? $data : ['raw' => substr($raw, 0, 200)]];
}
function say(string $s): void { echo $s, "\n"; }
function report(array $r): void
{
    foreach ($r['checks'] as $c) {
        say(sprintf('    %-5s %-18s %s', strtoupper($c['level']), $c['rule'], $c['message'] . (isset($c['hint']) && 'pass' !== $c['level'] ? '  -> ' . $c['hint'] : '')));
    }
    say(sprintf('    status: %s (%d pass, %d warn, %d fail)', $r['status'], $r['summary']['pass'], $r['summary']['warn'], $r['summary']['fail']));
}

say("1. The agent reads the OpenAPI document and the sections it may write to");
[$s, $doc] = call('GET', "$api/openapi.json");
say("   openapi.json: HTTP $s, " . count($doc['paths'] ?? []) . ' paths');
[$s, $sections] = call('GET', "$api/sections", $agentKey);
say("   sections: HTTP $s, " . implode(', ', array_map(fn ($x) => $x['name'] . '[' . implode(',', $x['locales']) . ']', $sections['items'])));

say("\n2. First draft, linted before it is even created");
$draft = [
    'section' => 'blog', 'locale' => 'en',
    'title' => 'Retries',
    'body' => "## Why\n\nMessenger retries. ![](/img/retry.png)\n\nRead more [here](https://symfony.com).",
    'tags' => [],
];
[$s, $r] = call('POST', "$api/lint", $agentKey, $draft);
say("   POST /lint: HTTP $s");
report($r);

say("\n3. The agent rewrites according to the hints and creates the draft");
$draft = [
    'section' => 'blog', 'locale' => 'en',
    'title' => 'Symfony Messenger Retries: Why a Throwing Serializer Loops Forever',
    'summary' => 'How Messenger counts retries, why a serializer that throws from decode() restarts the counter on every delivery, and the fix.',
    'slug' => 'symfony-messenger-retries-throwing-serializer',
    'tags' => ['symfony', 'messenger', 'php'],
    'translation_group' => 'messenger-retries',
    'body' => "Symfony Messenger retries a failed message with a growing delay, and the retry counter travels with the message in a stamp. When a custom serializer throws from decode() instead of returning the wrapped failure, every transport drops that stamp, so the worker starts again from retry one and the message never reaches the failure transport.\n\n"
        . "## How the counter works\n\n" . str_repeat('The worker reads the RedeliveryStamp, compares it with the retry strategy and either redelivers with a delay or sends the message to the failure transport. ', 8)
        . "See [the Messenger retry documentation](/docs/messenger-retries) for the configuration keys.\n\n"
        . "## Where it breaks\n\n" . str_repeat('A serializer that throws loses every stamp, because the transport wraps the exception with only its own stamps. The counter is gone, so the retry listener counts from zero again. ', 8) . "\n\n"
        . "## The fix\n\n" . str_repeat('Return MessageDecodingFailedException::wrap() with the stamps you could decode, or let the middleware mark the failure as unrecoverable, as Symfony 8.1.11 does. ', 6) . "\n\n"
        . "![Retry flow with and without the stamp](/img/retry.png)\n",
];
[$s, $r] = call('POST', "$api/lint", $agentKey, $draft);
say("   POST /lint again: HTTP $s, status {$r['status']}");
[$s, $article] = call('POST', "$api/articles", $agentKey, $draft);
say("   POST /articles: HTTP $s, id {$article['id']}, state {$article['state']}, slug {$article['slug']}");
$id = $article['id'];

say("\n4. The agent may not publish, only submit");
[$s, $r] = call('POST', "$api/articles/$id/publish", $agentKey);
say("   POST /publish with the agent key: HTTP $s, {$r['error']}");
[$s, $r] = call('POST', "$api/articles/$id/submit", $agentKey);
say("   POST /submit: HTTP $s, state {$r['state']}");

say("\n5. The editor rejects once, the agent fixes, the editor approves");
[$s, $r] = call('POST', "$api/articles/$id/reject", $editorKey, ['reason' => 'Add the version the fix landed in.']);
say("   POST /reject: HTTP $s, state {$r['state']}, review_note \"{$r['review_note']}\"");
[$s, $r] = call('PATCH', "$api/articles/$id", $agentKey, ['body' => $draft['body'] . "\nThe middleware change shipped in symfony/messenger 8.1.11.\n"]);
say("   PATCH body: HTTP $s");
call('POST', "$api/articles/$id/submit", $agentKey);
[$s, $r] = call('POST', "$api/articles/$id/approve", $editorKey);
say("   POST /approve: HTTP $s, state {$r['state']}, published_at {$r['published_at']}");

say("\n6. A German translation, scheduled for later, in the same translation group");
[$s, $de] = call('POST', "$api/articles", $agentKey, ['section' => 'blog', 'locale' => 'de', 'title' => 'Symfony Messenger: Warum ein werfender Serializer endlos wiederholt', 'summary' => 'Wie Messenger Wiederholungen zählt und warum ein werfender Serializer den Zähler zurücksetzt.', 'body' => str_repeat("Ein Absatz auf Deutsch, der die Frage im Titel beantwortet und den Leser direkt anspricht. ", 5) . "\n\n## Abschnitt\n\n" . str_repeat('Mehr Text. ', 200), 'tags' => ['symfony'], 'translation_group' => 'messenger-retries']);
[$s, $r] = call('POST', "$api/articles/{$de['id']}/publish", $editorKey, ['publish_at' => '2099-01-01T09:00:00+00:00']);
say("   POST /publish with publish_at: HTTP $s, state {$r['state']}, publish_at {$r['publish_at']}");
[$s, $r] = call('POST', "$api/articles/{$de['id']}/publish", $editorKey);
say("   POST /publish again, now: HTTP $s, state {$r['state']} (in production the scheduler does this when publish_at arrives)");

say("\n7. Readers arrive (the site page is rendered by the demo app, the bundle only counts)");
$page = "$base/blog/{$article['slug']}";
foreach ([
    ['Mozilla/5.0 Chrome/130', ''],
    ['Mozilla/5.0 Chrome/130', 'https://www.google.com/'],
    ['Mozilla/5.0 Chrome/130', 'https://www.google.com/'],
    ['Mozilla/5.0 Chrome/130', 'https://chatgpt.com/'],
    ['Mozilla/5.0 Chrome/130', 'https://news.ycombinator.com/'],
    ['Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)', ''],
    ['Mozilla/5.0 (compatible; ClaudeBot/1.0)', ''],
] as [$ua, $ref]) {
    $ctx = stream_context_create(['http' => ['header' => "User-Agent: $ua\r\nReferer: $ref", 'ignore_errors' => true]]);
    $html = (string) file_get_contents($page, false, $ctx);
    say(sprintf('   GET %s  ua=%-45s ref=%-28s -> %s', parse_url($page, PHP_URL_PATH), substr($ua, 0, 45), $ref ?: '(none)', str_contains($html, 'application/ld+json') ? 'HTML with JSON-LD' : 'HTML'));
}

say("\n8. The stats the bundle collected");
[$s, $r] = call('GET', "$api/articles/$id/stats", $editorKey);
say("   GET /articles/{id}/stats: HTTP $s, views {$r['views']}, crawler_views {$r['crawler_views']}, by_kind " . json_encode($r['by_kind']));
foreach ($r['referrers'] as $ref) {
    say(sprintf('     %-24s %-12s %d', $ref['host'] ?: '(none)', $ref['kind'], $ref['views']));
}
[$s, $r] = call('GET', "$api/stats/summary?section=blog", $editorKey);
say("   GET /stats/summary?section=blog: views {$r['views']}, crawler_views {$r['crawler_views']}");

say("\n9. What search engines and language models see");
say('   ' . trim((string) file_get_contents("$base/llms.txt")));
$sitemap = (string) file_get_contents("$base/sitemap-publisher.xml");
say('   sitemap: ' . substr_count($sitemap, '<url>') . ' url(s), ' . substr_count($sitemap, 'hreflang=') . ' hreflang link(s)');
preg_match('#<script type="application/ld\+json">(.*?)</script>#', (string) file_get_contents($page), $m);
say('   JSON-LD on the page: ' . substr($m[1] ?? '(none)', 0, 160) . '...');
