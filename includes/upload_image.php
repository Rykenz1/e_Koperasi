<?php
// includes/upload_image.php
// Shared product-image upload handling. Keeping it in one place means the
// storage backend (local disk today, S3 later) only has to change here.

const PRODUCT_IMAGE_DIR          = __DIR__ . '/../uploads/products';
const PRODUCT_IMAGE_URL_PREFIX   = 'uploads/products/';
const PRODUCT_IMAGE_DEFAULT      = 'uploads/products/default.png';
const PRODUCT_IMAGE_MAX_BYTES    = 5 * 1024 * 1024;

const PRODUCT_IMAGE_ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

/**
 * Validate and store an uploaded product image.
 *
 * @param array $file One entry from $_FILES, e.g. $_FILES['product_image'].
 * @return array{ok: bool, path: ?string, error: ?string}
 *         `path` is null when the user simply did not choose a file.
 */
function save_product_image(array $file): array
{
    $error_code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    // Optional field: no file selected is not an error.
    if ($error_code === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];
    }

    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'The image is larger than the server allows.',
        UPLOAD_ERR_FORM_SIZE  => 'The image is too large.',
        UPLOAD_ERR_PARTIAL    => 'The image was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server error: the upload temp directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server error: the uploaded file could not be written.',
        UPLOAD_ERR_EXTENSION  => 'The upload was blocked by a server extension.',
    ];

    if ($error_code !== UPLOAD_ERR_OK) {
        return [
            'ok'    => false,
            'path'  => null,
            'error' => $upload_errors[$error_code] ?? 'The image could not be uploaded.',
        ];
    }

    $tmp_path = $file['tmp_name'] ?? '';
    if ($tmp_path === '' || !is_uploaded_file($tmp_path)) {
        return ['ok' => false, 'path' => null, 'error' => 'Invalid upload.'];
    }

    $size = filesize($tmp_path);
    if ($size === false || $size > PRODUCT_IMAGE_MAX_BYTES) {
        return ['ok' => false, 'path' => null, 'error' => 'Image must be 5 MB or smaller.'];
    }

    // Verify the real content type - never trust the client-supplied type.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp_path);
    if (!isset(PRODUCT_IMAGE_ALLOWED_MIME[$mime])) {
        return ['ok' => false, 'path' => null, 'error' => 'Only JPG, PNG or WEBP images are allowed.'];
    }

    // Second gate: make sure the bytes actually decode as an image.
    if (@getimagesize($tmp_path) === false) {
        return ['ok' => false, 'path' => null, 'error' => 'The file is not a valid image.'];
    }

    if (!is_dir(PRODUCT_IMAGE_DIR)
        && !mkdir(PRODUCT_IMAGE_DIR, 0775, true)
        && !is_dir(PRODUCT_IMAGE_DIR)) {
        return ['ok' => false, 'path' => null, 'error' => 'Server error: the upload folder is not writable.'];
    }

    // Server-generated name: no user input, no path traversal, no overwrite.
    $filename    = uniqid('prod_', true) . '.' . PRODUCT_IMAGE_ALLOWED_MIME[$mime];
    $destination = PRODUCT_IMAGE_DIR . '/' . $filename;

    if (!move_uploaded_file($tmp_path, $destination)) {
        return ['ok' => false, 'path' => null, 'error' => 'Server error: the image could not be saved.'];
    }
    @chmod($destination, 0644);

    return [
        'ok'    => true,
        'path'  => PRODUCT_IMAGE_URL_PREFIX . $filename,
        'error' => null,
    ];
}

/**
 * Remove a stored product image. Silently ignores null, the placeholder,
 * and anything that resolves outside uploads/products/.
 */
function delete_product_image(?string $path): void
{
    if ($path === null || $path === PRODUCT_IMAGE_DEFAULT) {
        return;
    }
    if (!str_starts_with($path, PRODUCT_IMAGE_URL_PREFIX) || str_contains($path, '..')) {
        return;
    }

    $filename = basename($path);
    if ($filename === '' || $filename === 'default.png') {
        return;
    }

    $root     = realpath(PRODUCT_IMAGE_DIR);
    $absolute = realpath(PRODUCT_IMAGE_DIR . '/' . $filename);
    if ($root === false || $absolute === false || dirname($absolute) !== $root) {
        return;
    }

    @unlink($absolute);
}
