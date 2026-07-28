<?php
$this->Breadcrumbs->add(__('Settings'), false);
$tableHeadersHtml = $this->Setting->tableHeaders();

$this->start('settings');
echo $this->Setting->table(
    __('Deactivate Forum'),
    ['forum_disabled', 'forum_disabled_text'],
    $Settings
);

echo $this->Setting->table(
    __('Base Preferences'),
    ['forum_name', 'timezone'],
    $Settings
);

echo $this->Setting->table(
    __('Email'),
    ['forum_email', 'email_contact', 'email_register', 'email_system'],
    $Settings,
    ['sh' => 6]
);

echo $this->Setting->table(
    __('Moderation'),
    ['store_ip', 'store_ip_anonymized'],
    $Settings
);

echo $this->Setting->table(
    __('Registration'),
    ['tos_enabled', 'tos_url'],
    $Settings
);

echo $this->Setting->table(
    __('Edit'),
    ['edit_period', 'edit_delay'],
    $Settings
);

echo $this->Setting->table(
    __('View'),
    [
        'topics_per_page',
        'thread_depth_indent',
        'autolink',
        'bbcode_img',
        'quote_symbol',
        'signature_separator',
        'subject_maxlength',
        'video_domains_allowed',
    ],
    $Settings
);

echo $this->Setting->table(
    __d('nondynamic', 'content_embed.t'),
    [
        'content_embed_active',
        'content_embed_media',
        'content_embed_text',
    ],
    $Settings
);

echo $this->Setting->table(
    __('Category Chooser'),
    ['category_chooser_global', 'category_chooser_user_override'],
    $Settings
);

// The "Debug" section held one switch, stopwatch_get, which turned on the
// profiler chart in the page footer. That chart was rendered by StopwatchHelper
// through jQuery and has been unreachable since the SPA went; the helper is
// gone now, so the switch controls nothing. Its settings row is left in the
// database — inert, and not worth a migration.
$this->end('settings');
?>
<div id="settings_index" class="settings index">
    <div class="row">
        <div class="col-md-3 navbarsidelist">
            <nav class="nav nav-pills flex-column" style="position: sticky; top: 1rem;">
                <?php foreach ($this->Setting->getHeaders() as $key => $title) : ?>
                    <a href="#navHeaderAnchor<?= $key ?>" class="nav-link">
                        <?= $title ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </div>
        <div class="col-md-9">
            <h1><?php echo __('Settings'); ?></h1>
            <?= $this->fetch('settings') ?>
        </div>
    </div>
</div>
<script>
// Highlight the section currently in view in the sidebar — what Bootstrap's
// ScrollSpy used to do here. The anchors the helper emits are empty divs with
// no height, so this measures their position rather than observing them: the
// last anchor that has passed the top of the viewport is the current one.
// Without this the links still jump correctly, they just do not follow along.
(function () {
    var links = document.querySelectorAll('.navbarsidelist .nav-link');
    if (!links.length) {
        return;
    }

    var sections = [];
    Array.prototype.forEach.call(links, function (link) {
        var anchor = document.getElementById(link.getAttribute('href').slice(1));
        if (anchor) {
            sections.push({ link: link, anchor: anchor });
        }
    });
    if (!sections.length) {
        return;
    }

    var pending = false;
    function update() {
        pending = false;

        // A little below the top edge, so the heading one is reading counts as
        // current rather than the one just scrolled past.
        var line = 80;
        var current = sections[0];
        sections.forEach(function (section) {
            if (section.anchor.getBoundingClientRect().top <= line) {
                current = section;
            }
        });

        // At the very bottom the last section may never reach the line — no
        // scrolling left to do — so claim it explicitly.
        if (window.innerHeight + window.scrollY >= document.body.scrollHeight - 2) {
            current = sections[sections.length - 1];
        }

        sections.forEach(function (section) {
            section.link.classList.toggle('active', section === current);
        });
    }

    function schedule() {
        if (!pending) {
            pending = true;
            window.requestAnimationFrame(update);
        }
    }

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    update();
})();
</script>
