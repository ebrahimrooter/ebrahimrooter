// Bundles the thinking-orbs React component for the plain-JS phone app:
//   ThinkingOrbs.mount(element, props) -> { update(props), unmount() }
import React from 'react';
import { createRoot } from 'react-dom/client';
import { ThinkingOrb } from 'thinking-orbs';

window.ThinkingOrbs = {
  mount(el, props) {
    const root = createRoot(el);
    let cur = { ...props };
    const draw = () => root.render(React.createElement(ThinkingOrb, cur));
    draw();
    return {
      update(p) { cur = { ...cur, ...p }; draw(); },
      unmount() { root.unmount(); },
    };
  },
};
