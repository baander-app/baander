interface SearchSection {
  title: string;
  url: string;
  plain_excerpt: string;
}

interface SearchPage {
  url: string;
  plain_excerpt: string;
  meta: { title: string };
  sub_results: SearchSection[];
}

interface SearchResponse {
  results: { data(): Promise<SearchPage> }[];
}

interface Pagefind {
  search(query: string): Promise<SearchResponse>;
}

// The page renders the field, status line and results list, so the sidebar already has its final
// layout; this script only enables the field and wires it.
const mount = document.querySelector<HTMLElement>('#manual-search');
const field = mount?.querySelector<HTMLInputElement>('#manual-search-input');
const statusLine = mount?.querySelector<HTMLElement>('#manual-search-status');
const resultList = mount?.querySelector<HTMLOListElement>('#manual-search-results');
if (mount && field && statusLine && resultList) {
  mountSearch(mount, field, statusLine, resultList);
}

function mountSearch(container: HTMLElement, input: HTMLInputElement, status: HTMLElement, results: HTMLOListElement): void {
  input.disabled = false;

  let generation = 0;
  let timer: ReturnType<typeof setTimeout> | undefined;
  let library: Promise<Pagefind> | undefined;
  // Results show other pages' text, so the fonts for every indexed character load only once results appear.
  let resultFonts = container.dataset.resultFonts;

  input.addEventListener('input', () => {
    generation += 1;
    const current = generation;
    clearTimeout(timer);
    results.replaceChildren();
    results.hidden = true;
    const query = input.value.trim();
    results.setAttribute('aria-busy', query ? 'true' : 'false');
    if (!query) {
      status.textContent = 'Search the operator and developer guides.';
      return;
    }
    status.textContent = 'Searching…';
    timer = setTimeout(() => {
      search(query, current).catch(() => {
        if (current !== generation) return;
        results.setAttribute('aria-busy', 'false');
        status.textContent = 'Search is unavailable. Browse the guides or reload this page to try again.';
      });
    }, 150);
  });

  async function search(query: string, current: number): Promise<void> {
    const moduleUrl = '/pagefind/pagefind.js';
    library ??= import(moduleUrl) as Promise<Pagefind>;
    const pagefind = await library;
    if (current !== generation) return;
    const response = await pagefind.search(query);
    if (current !== generation) return;
    const pages = await Promise.all(response.results.slice(0, 8).map((result) => result.data()));
    if (current !== generation) return;
    results.setAttribute('aria-busy', 'false');
    if (!pages.length) {
      status.textContent = `No matches for “${query}”.`;
      return;
    }

    for (const page of pages) {
      const item = document.createElement('li');
      const context = document.createElement('p');
      context.className = 'search-context';
      context.textContent = page.url.startsWith('/docs/operator-guide/')
        ? 'Operator guide'
        : 'Developer guide';
      item.append(context);
      const title = document.createElement('a');
      title.href = page.url;
      title.textContent = page.meta.title;
      item.append(title);
      const sections = page.sub_results.length ? page.sub_results.slice(0, 3) : [{
        title: page.meta.title,
        url: page.url,
        plain_excerpt: page.plain_excerpt,
      }];
      for (const section of sections) {
        if (section.url !== page.url) {
          const heading = document.createElement('a');
          heading.className = 'search-section';
          heading.href = section.url;
          heading.textContent = section.title;
          item.append(heading);
        }
        const excerpt = document.createElement('p');
        excerpt.className = 'search-excerpt';
        excerpt.textContent = section.plain_excerpt;
        item.append(excerpt);
      }
      results.append(item);
    }
    if (resultFonts) {
      const stylesheet = document.createElement('link');
      stylesheet.rel = 'stylesheet';
      stylesheet.href = resultFonts;
      document.head.append(stylesheet);
      resultFonts = undefined;
    }
    results.hidden = false;
    status.textContent = response.results.length > pages.length
      ? `Showing ${pages.length} of ${response.results.length} matching pages.`
      : `${pages.length} matching ${pages.length === 1 ? 'page' : 'pages'}.`;
  }

  container.addEventListener('keydown', (event) => {
    const links = [...results.querySelectorAll<HTMLAnchorElement>('a')];
    if (!links.length) return;
    const index = links.findIndex((link) => link === document.activeElement);
    if (event.key === 'ArrowDown' && (event.target === input || index >= 0)) {
      event.preventDefault();
      links[Math.min(index + 1, links.length - 1)].focus();
    } else if (event.key === 'ArrowUp' && index >= 0) {
      event.preventDefault();
      (index === 0 ? input : links[index - 1]).focus();
    } else if (event.key === 'Escape') {
      input.focus();
    }
  });
}
