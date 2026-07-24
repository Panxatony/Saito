<?php

$out = [
    'data' => array_map(
        fn ($image) => $this->ImageUploader->image($image),
        $images
    ),
];

// Per-file failures in a multi-file batch (empty when everything stored).
if (!empty($uploadErrors)) {
    $out['errors'] = array_map(fn ($message) => ['title' => $message], $uploadErrors);
}

echo json_encode($out);
