import { DemoApp } from "./demo/DemoApp";
import { Benefits } from "./marketing/Benefits";
import { Footer } from "./marketing/Footer";
import { Header } from "./marketing/Header";
import { Hero } from "./marketing/Hero";
import { Pilot } from "./marketing/Pilot";
import { ProductTour } from "./marketing/ProductTour";
import { Technical } from "./marketing/Technical";
import { Trust } from "./marketing/Trust";

export default function App() {
  return (
    <>
      <a className="skip-link" href="#main-content">Skip to content</a>
      <Header />
      <main id="main-content">
        <Hero />
        <section className="demo-section" id="demo" aria-label="Interactive placement-day demo">
          <div className="demo-section-heading">
            <span className="section-number" aria-hidden="true">01</span>
            <div><p>Interactive demo</p><h2>Help Dev reach both interviews.</h2></div>
            <p>Atlas and Nova are scheduled five minutes apart. Resolve the clash, coordinate the handoffs, and record the outcome.</p>
          </div>
          <DemoApp />
        </section>
        <Benefits />
        <ProductTour />
        <Trust />
        <Technical />
        <Pilot />
      </main>
      <Footer />
    </>
  );
}
