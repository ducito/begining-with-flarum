<?php namespace Flarum\Tags\Commands;

use Flarum\Tags\Tag;
use Flarum\Tags\TagRepository;

class DeleteTagHandler
{
    /**
     * @var TagRepository
     */
    protected $tags;

    /**
     * @param TagRepository $tags
     */
    public function __construct(TagRepository $tags)
    {
        $this->tags = $tags;
    }

    /**
     * @param DeleteTag $command
     * @return Tag
     * @throws \Flarum\Core\Exceptions\PermissionDeniedException
     */
    public function handle(DeleteTag $command)
    {
        $actor = $command->actor;

        $tag = $this->tags->findOrFail($command->tagId, $actor);

        $tag->assertCan($actor, 'delete');

        $tag->delete();

        return $tag;
    }
}
