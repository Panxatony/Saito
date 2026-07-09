<?php
$out = $entry->get('subject');
if (!$entry->isNt()) {
    $out .= "\n\n" . $entry->get('text');
}
// `subject`/`text` are stored raw (BBCode/escaping happens at render time),
// so this raw-source view must escape them itself — HtmlHelper::tag() does not
// escape unless told to, which otherwise makes this a stored-XSS sink.
echo $this->Html->tag('pre', $out, ['escape' => true, 'style' => 'white-space: pre-wrap']);
