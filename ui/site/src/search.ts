interface SearchSection {
  title: string;
  url: string;
  plain_excerpt: string;
  weighted_locations: { balanced_score: number }[];
}

interface SearchPage {
  url: string;
  plain_excerpt: string;
  // `label` names the manual or reference page a result comes from; each indexed page sets it.
  meta: { title: string; label: string };
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
// `status` is taken by the window in this classic script, so the page's elements get their own names here.
const mount = document.querySelector<HTMLElement>('#manual-search');
const field = mount?.querySelector<HTMLInputElement>('#manual-search-input');
const statusLine = mount?.querySelector<HTMLElement>('#manual-search-status');
const resultList = mount?.querySelector<HTMLOListElement>('#manual-search-results');
if (mount && field && statusLine && resultList) {
  mountSearch(mount, field, statusLine, resultList);
}

function mountSearch(container: HTMLElement, input: HTMLInputElement, status: HTMLElement, results: HTMLOListElement): void {
  input.disabled = false;
  const idleStatus = status.textContent;

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
      status.textContent = idleStatus;
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
      context.textContent = page.meta.label;
      item.append(context);
      const title = document.createElement('a');
      title.href = page.url;
      title.textContent = page.meta.title;
      item.append(title);
      const sections = page.sub_results.length ? bestSections(page.sub_results) : [{
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

  // Pagefind lists a page's sections in page order; on a long page such as the glossary the matching
  // section would fall outside the first three, so the sections with the strongest matches are shown.
  function bestSections(sections: SearchSection[]): SearchSection[] {
    return sections
      .map((section) => ({
        section,
        score: section.weighted_locations.reduce((total, location) => total + location.balanced_score, 0),
      }))
      .sort((first, second) => second.score - first.score)
      .slice(0, 3)
      .map(({ section }) => section);
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
