<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Models\LinkedInPost;
use Throwable;

final class ShareCommand extends Command
{
    protected $signature = 'linkedin:share
        {model : Morph alias or model class}
        {id : Primary key}
        {--page=* : A page id (author URN) to share to instead of the selected pages; repeat for several}
        {--force : Share again even if it was already posted}';

    protected $description = 'Share a model on LinkedIn now';

    public function handle(LinkedInManager $linkedin): int
    {
        $type = $this->stringArgument('model');
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, ShareableOnLinkedIn::class)) {
            $this->components->error("[{$type}] is not a shareable model.");

            return self::FAILURE;
        }

        $id = $this->stringArgument('id');

        /** @var (Model&ShareableOnLinkedIn)|null $model */
        $model = $class::query()->find($id);

        if ($model === null) {
            $this->components->error("Record [{$id}] not found.");

            return self::FAILURE;
        }

        if (! $this->option('force') && $linkedin->wasPosted($model)) {
            $this->components->warn('This was already posted to LinkedIn. Use --force to share it again.');

            return self::FAILURE;
        }

        $pages = array_values(array_filter(array_map('strval', (array) $this->option('page'))));

        try {
            $records = $linkedin->shareToPages($model, $pages !== [] ? $pages : null, LinkedInPost::TRIGGER_CONSOLE);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($records as $post) {
            $page = $post->getAttributes()['author_urn'] ?? null;
            $where = $records->count() > 1 && is_string($page) ? " ({$page})" : '';

            if ($post->status === LinkedInPost::STATUS_FAILED) {
                $this->components->error("Not shared{$where}: {$post->error}");
            } else {
                $this->components->info($post->post_urn !== null
                    ? "Shared on LinkedIn{$where}: {$post->post_urn}"
                    : "Shared on LinkedIn{$where} (LinkedIn returned no post id).");
            }
        }

        return $records->contains('status', LinkedInPost::STATUS_FAILED) ? self::FAILURE : self::SUCCESS;
    }

    private function stringArgument(string $name): string
    {
        $value = $this->argument($name);

        return is_scalar($value) ? (string) $value : '';
    }
}
