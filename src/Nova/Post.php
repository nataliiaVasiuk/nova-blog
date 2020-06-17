<?php

namespace OptimistDigital\NovaBlog\Nova;

use Illuminate\Http\Request;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Image;
use Laravel\Nova\Fields\Markdown;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use OptimistDigital\NovaBlog\Nova\Fields\Slug;
use OptimistDigital\NovaBlog\Nova\Fields\Title;
use OptimistDigital\NovaBlog\NovaBlog;
use Whitecube\NovaFlexibleContent\Flexible;
use Laravel\Nova\Fields\Trix;
use Froala\NovaFroalaField\Froala;
use OptimistDigital\NovaBlog\Models\Post as PostModel;
use OptimistDigital\MultiselectField\Multiselect;
use OptimistDigital\NovaBlog\Models\RelatedPost;

class Post extends TemplateResource
{
    public static $title = 'name';
    public static $model = 'OptimistDigital\NovaBlog\Models\Post';
    public static $displayInNavigation = false;

    protected $type = 'post';

    public function fields(Request $request)
    {
        // Get base data
        $tableName = config('nova-blog.blog_posts_table', 'nova_blog_posts');
        $templateClass = $this->getTemplateClass();
        $templateFieldsAndPanels = $this->getTemplateFieldsAndPanels();

        $relatedPostOptions = [];
        \OptimistDigital\NovaBlog\Models\Post::all()->filter(function ($post) {
            return $post->id !== $this->id;
        })->each(function ($post) use (&$relatedPostOptions) {
            $relatedPostOptions[$post->id] = $post->title;
        });

        $relatedPosts = RelatedPost::where('post_id', $this->id)->pluck('related_post_id');

        $postContent = Flexible::make('Post content', 'post_content')->hideFromIndex()
            ->addLayout('Text section', 'text', [
                config('nova-blog.use_trix') === true ? Trix::make('Text content', 'text_content') : Markdown::make('Text content', 'text_content'),
            ])
            ->addLayout('Image section', 'image', [
                Image::make('Image', 'image')->deletable(false)->creationRules('required'),
                Text::make('Image caption', 'caption'),
                Text::make('Alt (image alternate text)', 'alt')
            ])
            ->addLayout('Other embed media section', 'other_media', [
                Textarea::make('Embed media code (twitter, iframe, etc.)', 'media_code'),
                Text::make('Media caption', 'caption')
            ]);

        if (config('nova-blog.include_froala_texteditor_option')) {
            $postContent->addLayout('Text section in Froala', 'text_froala', [
                Froala::make('Text section in Froala', 'text_content_froala'),
            ]);
        }

        $fields = [
            ID::make()->sortable(),
            config('nova-blog.use_trix') === true ? Trix::make('Title', 'title')->rules('required')->alwaysShow() : Title::make('Title', 'title')->rules('required')->alwaysShow(),
            config('nova-blog.hide_pinned_post_option') === true ? null : Boolean::make('Is pinned', 'is_pinned'),
            config('nova-blog.include_include_in_bloglist') === true ? Boolean::make('Include in bloglist', 'include_in_bloglist') : null,
            Slug::make('Slug', 'slug')->rules('required', 'alpha_dash_or_slash')->onlyOnForms(),
            Text::make('Slug', function () {
                $previewToken = $this->childDraft ? $this->childDraft->preview_token : $this->preview_token;
                $previewPart = $previewToken ? '?preview=' . $previewToken : '';
                $pagePath = $this->resource->slug;
                $pageBaseUrl = NovaBlog::getPageUrl($this->resource);
                $pageUrl = !empty($pageBaseUrl) ? $pageBaseUrl . $previewPart : null;
                $buttonText = $this->resource->isDraft() ? 'View draft' : 'View';

                if (empty($pageBaseUrl)) return "<span class='bg-40 text-sm py-1 px-2 rounded-lg whitespace-no-wrap'>$pagePath</span>";

                return "<div class='whitespace-no-wrap'>
                            <span class='bg-40 text-sm py-1 px-2 rounded-lg'>$pagePath</span>
                            <a target='_blank' href='$pageUrl' class='text-sm py-1 px-2 text-primary no-underline dim font-bold'>$buttonText</a>
                        </div>";
            })->asHtml()->exceptOnForms(),
            DateTime::make('Published at', 'published_at')->rules('required'),
            Textarea::make('Post introduction', 'post_introduction'),
            config('nova-blog.include_featured_image') === true ? Image::make('Featured image', 'featured_image') : null,
            config('nova-blog.hide_category_selector') === true ? null : BelongsTo::make('Category', 'category', 'OptimistDigital\NovaBlog\Nova\Category')->nullable(),


            $postContent,
            config('nova-blog.include_related_posts_feature') === true ?
                Multiselect
                ::make('Related posts', 'related_posts')
                ->options($relatedPostOptions)
                ->withMeta(['value' => $relatedPosts])
                : null,



        ];

        if (NovaBlog::hasNovaLang()) {
            $fields[] = \OptimistDigital\NovaLang\NovaLangField\NovaLangField::make('Locale', 'locale');
        }

        if (NovaBlog::hasNovaDrafts()) {
            $fields[] = \OptimistDigital\NovaDrafts\DraftButton::make('Draft');
            $fields[] = \OptimistDigital\NovaDrafts\PublishedField::make('State', 'published');
        }

        $fields[] = new Panel('SEO', $this->getSeoFields());

        if (count($templateFieldsAndPanels['fields']) > 0) {
            $fields[] = new Panel(
                'Page data',
                array_merge(
                    [Heading::make('Page data')->hideFromDetail()],
                    $templateFieldsAndPanels['fields']
                )
            );
        }
        if (count($templateFieldsAndPanels['panels']) > 0) {
            $fields = array_merge($fields, $templateFieldsAndPanels['panels']);
        }

        return collect($fields)->filter(function ($field) {
            return $field !== null;
        })->toArray();
    }

    protected function getSeoFields()
    {
        return [
            Heading::make('SEO'),
            Text::make('SEO Title', 'seo_title')->hideFromIndex(),
            Text::make('SEO Description', 'seo_description')->hideFromIndex(),
            Image::make('SEO Image', 'seo_image')->hideFromIndex(),
        ];
    }

    public function title()
    {
        return $this->name . ' (' . $this->slug . ')';
    }

    public static function indexQuery(NovaRequest $request, $query)
    {
        $column = config('nova-blog.blog_posts_table', 'nova_blog_posts') . '.locale';
        $query->doesntHave('childDraft');
        if (NovaBlog::hasNovaLang())
            $query->where(function ($subQuery) use ($column) {
                $subQuery->where($column, nova_lang_get_active_locale())
                    ->orWhereNotIn($column, array_keys(nova_lang_get_all_locales()));
            });
        return $query;
    }
}
