import { Icon } from "../demo/Icon";

export function Header() {
  return (
    <header className="site-header" id="top">
      <a className="wordmark" href="#top" aria-label="Campus Placement Engine home">
        <span className="wordmark-mark" aria-hidden="true">CP</span>
        <span>Campus Placement Engine</span>
      </a>
      <nav aria-label="Primary navigation">
        <a href="#benefits">Benefits</a>
        <a href="#demo">Demo</a>
        <a href="#trust">Privacy</a>
        <a href="#technical">For IT</a>
      </nav>
      <a className="header-cta" href="#demo">Start the demo <Icon name="arrow" size={17} /></a>
    </header>
  );
}
