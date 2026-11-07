<?php

declare(strict_types=1);

use craft\web\View;

function renderPanel(string $with = '', string $body = '<p>Body</p>'): string
{
	$template = "{% embed 'hub/_components/panel' with { heading: 'Sites'{$with} } %}{% block body %}{$body}{% endblock %}{% endembed %}";

	// Escaped as the templates of pages are, since Twig keeps how it compiled the panel for the other tests.
	return Craft::$app->getView()->renderString($template, [], View::TEMPLATE_MODE_CP, escapeHtml: true);
}

it('renders collapsible panels closed, holding a placeholder that loads them from their page', function (): void {
	$this->request(['q' => 'news'], path: 'hub/structure/sections/2');

	$html = renderPanel();

	expect($html)->toContain('<details class="hub-panel__details" data-hub-panel="sites" data-hub-lazy="')
		->and($html)->toContain('panel=sites')
		->and($html)->toContain('q=news')
		->and($html)->toContain('data-hub-lazy-status')
		->and($html)->not->toContain(' open')
		->and($html)->not->toContain('<p>Body</p>');
});

it('keys panels by their key, or else their id', function (): void {
	$this->request(path: 'hub/structure/sections/2');

	expect(renderPanel(", id: 'hub-sites'"))->toContain('data-hub-panel="hub-sites"')
		->and(renderPanel(", id: 'hub-sites'"))->toContain('panel=hub-sites')
		->and(renderPanel(", id: 'hub-sites', panelKey: 'sites'"))->toContain('data-hub-panel="sites"')
		->and(renderPanel(", id: 'hub-sites', panelKey: 'sites'"))->toContain('id="hub-sites"');
});

it('loads panels from the page of their pagination', function (): void {
	$this->request(path: 'hub/data/entries/2/history');
	(fn(): int => $this->_pageNum = 3)->call(Craft::$app->getRequest());

	expect(renderPanel())->toContain('history/p3&amp;panel=sites');

	(fn(): int => $this->_pageNum = 1)->call(Craft::$app->getRequest());
});

it('renders the body of loaded panels', function (): void {
	$this->request();

	$html = renderPanel(', loaded: true');

	expect($html)->toContain('<p>Body</p>')
		->and($html)->not->toContain('data-hub-lazy');
});

it('renders the scripts the body of the panel requested registers along with it', function (): void {
	$this->request(['panel' => 'sites']);

	$html = renderPanel(', loaded: true', "<p>Body</p>{% js %}console.log('loaded');{% endjs %}");

	expect($html)->toContain('<p>Body</p>')
		->and($html)->toContain("console.log('loaded');")
		->and($html)->toContain('<script');
});

it('loads panels from the URL they are passed', function (): void {
	$this->request();

	expect(renderPanel(", lazy: '/load'"))->toContain('data-hub-lazy="/load"')
		->and(renderPanel(", lazy: '/load'"))->not->toContain('<p>Body</p>');
});

it('renders the body of panels which cannot be collapsed', function (): void {
	$this->request();

	expect(renderPanel(', collapsible: false'))->toContain('<p>Body</p>')
		->and(renderPanel(', collapsible: false'))->not->toContain('data-hub-lazy');
});
