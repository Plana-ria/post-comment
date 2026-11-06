<?php
declare(strict_types=1);

namespace PostComment;

use InvalidArgumentException;

final class FormDefinition
{
    public static function defaults(): array
    {
        return [
            'schema_version' => 1,
            'title' => 'コメント投稿',
            'description' => 'コメントログは、本人のユーザーIDでログインした場合にダウンロードできます。 / Your comment log is available after signing in with your own user ID.',
            'submit_label' => '送信',
            'fields' => [
                ['key' => 'last_name', 'type' => 'text', 'label' => '姓', 'required' => true, 'placeholder' => '姓 / Last name'],
                ['key' => 'first_name', 'type' => 'text', 'label' => '名', 'required' => true, 'placeholder' => '名 / First name'],
                ['key' => 'affiliation', 'type' => 'text', 'label' => '所属（学部・学科など）', 'required' => true, 'placeholder' => '所属（学部・学科など） / Affiliation'],
                ['key' => 'email', 'type' => 'email', 'label' => 'メールアドレス', 'required' => false, 'placeholder' => 'メールアドレス / E-mail', 'description' => '入力するよう指示がある場合のみ入力してください / Only if instructed, enter your email address'],
                ['key' => 'comment', 'type' => 'textarea', 'label' => 'コメント', 'required' => true, 'role' => 'comment_body', 'placeholder' => 'コメントを入力してください / Enter your comments'],
            ],
        ];
    }

    public static function guest(): array
    {
        return [
            'schema_version'=>1,
            'title'=>'コメント投稿',
            'description'=>'',
            'submit_label'=>'送信',
            'fields'=>[
                ['key'=>'name','type'=>'text','label'=>'名前','required'=>true],
                ['key'=>'comment','type'=>'textarea','label'=>'コメント','required'=>true,'role'=>'comment_body'],
            ],
        ];
    }

    public static function validate(array $definition): void
    {
        $fields = $definition['fields'] ?? null;
        if (!is_array($fields) || count($fields) < 1 || count($fields) > 40) {
            throw new InvalidArgumentException('Form must have 1–40 fields.');
        }
        $seen = [];
        $bodyCount = 0;
        foreach ($fields as $field) {
            if (!is_array($field)) {
                throw new InvalidArgumentException('Invalid form field.');
            }
            $key = $field['key'] ?? '';
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,49}$/', $key) || isset($seen[$key])) {
                throw new InvalidArgumentException('Invalid or duplicate field key.');
            }
            // These keys are controlled by the HTTP router and must not be
            // shadowed by participant-controlled form fields.
            if (in_array($key, [
                'csrf','slug','route','form_version','action','room_id',
                'user_id','admin_id','login_id','login_code',
                'setup_key','secret','encoding'
            ], true)) {
                throw new InvalidArgumentException('Reserved form field key.');
            }
            $seen[$key] = true;
            if (!in_array($field['type'] ?? '', ['text', 'textarea', 'email', 'select', 'radio', 'checkbox'], true)) {
                throw new InvalidArgumentException('Unsupported field type.');
            }
            if (!is_string($field['label'] ?? null) || trim($field['label']) === '') {
                throw new InvalidArgumentException('Field label is required.');
            }
            if (mb_strlen($field['label']) > 160) {
                throw new InvalidArgumentException('Field label is too long.');
            }
            foreach (['description'=>500,'placeholder'=>200] as $setting=>$maximum) {
                if (isset($field[$setting]) && (!is_string($field[$setting]) || mb_strlen($field[$setting])>$maximum)) {
                    throw new InvalidArgumentException('Invalid field display setting.');
                }
            }
            if (isset($field['required']) && !is_bool($field['required'])) {
                throw new InvalidArgumentException('Required flag must be boolean.');
            }
            if (in_array($field['type'], ['select','radio'], true)) {
                $options = $field['options'] ?? null;
                if (!is_array($options) || count($options) < 1 || count($options) > 30) {
                    throw new InvalidArgumentException('Invalid selectable options.');
                }
                foreach ($options as $option) {
                    if (!is_string($option) || $option === '' || mb_strlen($option) > 120) {
                        throw new InvalidArgumentException('Invalid option text.');
                    }
                }
                if (count(array_unique($options)) !== count($options)) {
                    throw new InvalidArgumentException('Duplicate options.');
                }
            }
            if (($field['role'] ?? '') === 'comment_body') {
                ++$bodyCount;
                if (empty($field['required']) || !in_array($field['type'], ['text', 'textarea'], true)) {
                    throw new InvalidArgumentException('Comment body must be a required text field.');
                }
            }
        }
        if ($bodyCount !== 1) {
            throw new InvalidArgumentException('Exactly one required comment body field is needed.');
        }
    }
}
