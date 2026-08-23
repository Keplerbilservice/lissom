/* @ds-bundle: {"format":4,"namespace":"LissomDesignSystem_8402ac","components":[{"name":"Badge","sourcePath":"components/core/Badge.jsx"},{"name":"Button","sourcePath":"components/core/Button.jsx"},{"name":"Card","sourcePath":"components/core/Card.jsx"},{"name":"Divider","sourcePath":"components/core/Divider.jsx"},{"name":"Icon","sourcePath":"components/core/Icon.jsx"},{"name":"IconButton","sourcePath":"components/core/IconButton.jsx"},{"name":"Logo","sourcePath":"components/core/Logo.jsx"},{"name":"Tag","sourcePath":"components/core/Tag.jsx"},{"name":"Dialog","sourcePath":"components/feedback/Dialog.jsx"},{"name":"Toast","sourcePath":"components/feedback/Toast.jsx"},{"name":"Tooltip","sourcePath":"components/feedback/Tooltip.jsx"},{"name":"Checkbox","sourcePath":"components/forms/Checkbox.jsx"},{"name":"Input","sourcePath":"components/forms/Input.jsx"},{"name":"Radio","sourcePath":"components/forms/Radio.jsx"},{"name":"Select","sourcePath":"components/forms/Select.jsx"},{"name":"Switch","sourcePath":"components/forms/Switch.jsx"},{"name":"Textarea","sourcePath":"components/forms/Textarea.jsx"},{"name":"BottomNav","sourcePath":"components/navigation/BottomNav.jsx"},{"name":"NavBar","sourcePath":"components/navigation/NavBar.jsx"},{"name":"Tabs","sourcePath":"components/navigation/Tabs.jsx"},{"name":"CourseCard","sourcePath":"components/patterns/CourseCard.jsx"}],"sourceHashes":{"components/core/Badge.jsx":"8b643108e707","components/core/Button.jsx":"e105d1d4623e","components/core/Card.jsx":"da42fd2f8baa","components/core/Divider.jsx":"fc8f8a2fe8fc","components/core/Icon.jsx":"86cae9e071f1","components/core/IconButton.jsx":"f1f4fd124842","components/core/Logo.jsx":"5dc2c9149cfc","components/core/Tag.jsx":"a034d5a7e45d","components/feedback/Dialog.jsx":"9b0d5d73b4eb","components/feedback/Toast.jsx":"73437d4404c1","components/feedback/Tooltip.jsx":"fd35fbd0f99f","components/forms/Checkbox.jsx":"98c4401691c7","components/forms/Input.jsx":"6acd386fb7f7","components/forms/Radio.jsx":"419b53e58ff2","components/forms/Select.jsx":"0f84fd9f7bd0","components/forms/Switch.jsx":"5ae8598852c5","components/forms/Textarea.jsx":"64bf8399f467","components/navigation/BottomNav.jsx":"b5ba3715dfff","components/navigation/NavBar.jsx":"775ec106e2be","components/navigation/Tabs.jsx":"c0d88a279b9f","components/patterns/CourseCard.jsx":"02a1cd44bde4","ui_kits/app/Screens.jsx":"c93dccc36ac0","ui_kits/app/app.jsx":"e8bef7402b58","ui_kits/website/Forside.jsx":"87c5b78dc7b8","ui_kits/website/Kurs.jsx":"e883703e1dae","ui_kits/website/Medlemskap.jsx":"78bd6a4a88be","ui_kits/website/Shell.jsx":"080e3b0c4755","ui_kits/website/app.jsx":"5e5f1633d440"},"inlinedExternals":[],"unexposedExports":[]} */

(() => {

const __ds_ns = (window.LissomDesignSystem_8402ac = window.LissomDesignSystem_8402ac || {});

const __ds_scope = {};

(__ds_ns.__errors = __ds_ns.__errors || []);

// components/core/Badge.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const TONES = {
  neutral: {
    background: 'var(--clay-100)',
    color: 'var(--clay-700)'
  },
  brand: {
    background: 'var(--lissom-yellow)',
    color: 'var(--lissom-brown)'
  },
  ink: {
    background: 'var(--lissom-brown)',
    color: 'var(--clay-50)'
  },
  success: {
    background: 'var(--success-bg)',
    color: 'var(--success)'
  },
  warning: {
    background: 'var(--warning-bg)',
    color: 'var(--warning)'
  },
  danger: {
    background: 'var(--danger-bg)',
    color: 'var(--danger)'
  }
};
function Badge({
  tone = 'neutral',
  children,
  style,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("span", _extends({
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      padding: '4px 10px',
      borderRadius: 'var(--radius-pill)',
      fontFamily: 'var(--font-sans)',
      fontWeight: 700,
      fontSize: 'var(--text-2xs)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      ...(TONES[tone] || TONES.neutral),
      ...style
    }
  }, rest), children);
}
Object.assign(__ds_scope, { Badge });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Badge.jsx", error: String((e && e.message) || e) }); }

// components/core/Card.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const TONES = {
  paper: {
    background: 'var(--surface-card)',
    border: '1px solid var(--border-subtle)'
  },
  clay: {
    background: 'var(--clay-100)',
    border: '1px solid var(--clay-200)'
  },
  sun: {
    background: 'var(--lissom-yellow)',
    border: '1px solid var(--lissom-yellow)'
  },
  ink: {
    background: 'var(--lissom-brown)',
    border: '1px solid var(--lissom-brown)',
    color: 'var(--clay-100)'
  }
};
function Card({
  tone = 'paper',
  elevation = 'sm',
  interactive,
  padding = 'var(--card-pad)',
  children,
  style,
  ...rest
}) {
  const [h, setH] = React.useState(false);
  return /*#__PURE__*/React.createElement("div", _extends({
    onMouseEnter: () => setH(true),
    onMouseLeave: () => setH(false),
    style: {
      borderRadius: 'var(--radius-xl, 22px)',
      padding,
      overflow: 'hidden',
      boxShadow: interactive && h ? 'var(--shadow-md)' : 'var(--shadow-' + elevation + ')',
      transform: interactive && h ? 'translateY(-2px)' : 'none',
      transition: 'box-shadow var(--duration-base) var(--ease-clay), transform var(--duration-base) var(--ease-clay)',
      cursor: interactive ? 'pointer' : undefined,
      ...(TONES[tone] || TONES.paper),
      ...style
    }
  }, rest), children);
}
Object.assign(__ds_scope, { Card });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Card.jsx", error: String((e && e.message) || e) }); }

// components/core/Divider.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Divider({
  tone = 'subtle',
  ornament,
  style,
  ...rest
}) {
  const color = tone === 'ink' ? 'var(--border-ink-rule)' : 'var(--border-subtle)';
  if (ornament) {
    return /*#__PURE__*/React.createElement("div", _extends({
      style: {
        display: 'flex',
        alignItems: 'center',
        gap: 'var(--space-4)',
        ...style
      }
    }, rest), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        height: 1,
        background: color
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        width: 6,
        height: 6,
        borderRadius: '50%',
        background: color
      }
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        flex: 1,
        height: 1,
        background: color
      }
    }));
  }
  return /*#__PURE__*/React.createElement("hr", _extends({
    style: {
      border: 0,
      borderTop: (tone === 'ink' ? '2px' : '1px') + ' solid ' + color,
      margin: 0,
      ...style
    }
  }, rest));
}
Object.assign(__ds_scope, { Divider });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Divider.jsx", error: String((e && e.message) || e) }); }

// components/core/Icon.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const CACHE = {};
const BASE = '/icons/';
// Ikonene laa foer paa unpkg.com og ble hentet over nett for hver visning.
// De ligger naa i /icons/ her paa serveren; unpkg staar igjen som reserve
// hvis et ikon skulle mangle lokalt.
const RESERVE_BASE = 'https://unpkg.com/lucide-static@0.428.0/icons/';

/** Inline Lucide SVG so it inherits currentColor. */
function Icon({
  name,
  size = 20,
  strokeWidth = 1.75,
  label,
  style,
  ...rest
}) {
  const [svg, setSvg] = React.useState(CACHE[name] || null);
  React.useEffect(() => {
    let alive = true;
    if (CACHE[name]) {
      setSvg(CACHE[name]);
      return;
    }
    fetch(BASE + name + '.svg')
      .then(r => r.ok ? r.text() : fetch(RESERVE_BASE + name + '.svg').then(r2 => r2.ok ? r2.text() : ''))
      .then(t => {
      CACHE[name] = t;
      if (alive) setSvg(t);
    }).catch(() => {});
    return () => {
      alive = false;
    };
  }, [name]);
  return /*#__PURE__*/React.createElement("span", _extends({
    role: label ? 'img' : 'presentation',
    "aria-label": label,
    "aria-hidden": label ? undefined : true,
    style: {
      display: 'inline-flex',
      width: size,
      height: size,
      flex: '0 0 auto',
      color: 'currentColor',
      strokeWidth,
      ...style
    },
    dangerouslySetInnerHTML: {
      __html: (svg || '').replace('<svg', '<svg width="100%" height="100%" stroke-width="' + strokeWidth + '"')
    }
  }, rest));
}
Object.assign(__ds_scope, { Icon });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Icon.jsx", error: String((e && e.message) || e) }); }

// components/core/Button.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const SIZES = {
  sm: {
    padding: '8px 16px',
    fontSize: 'var(--text-xs)',
    gap: 6,
    icon: 15,
    minHeight: 34
  },
  md: {
    padding: '11px 22px',
    fontSize: 'var(--text-sm)',
    gap: 8,
    icon: 17,
    minHeight: 44
  },
  lg: {
    padding: '15px 30px',
    fontSize: 'var(--text-base)',
    gap: 10,
    icon: 20,
    minHeight: 52
  }
};
const VARIANTS = {
  primary: {
    background: 'var(--lissom-yellow)',
    color: 'var(--lissom-brown)',
    border: '2px solid var(--lissom-yellow)'
  },
  secondary: {
    background: 'transparent',
    color: 'var(--lissom-brown)',
    border: '2px solid var(--lissom-brown)'
  },
  ink: {
    background: 'var(--lissom-brown)',
    color: 'var(--clay-50)',
    border: '2px solid var(--lissom-brown)'
  },
  ghost: {
    background: 'transparent',
    color: 'var(--text-body)',
    border: '2px solid transparent'
  }
};
const HOVER = {
  primary: {
    background: 'var(--yellow-500)',
    borderColor: 'var(--yellow-500)'
  },
  secondary: {
    background: 'var(--lissom-brown)',
    color: 'var(--clay-50)'
  },
  ink: {
    background: 'var(--brown-700)',
    borderColor: 'var(--brown-700)'
  },
  ghost: {
    background: 'var(--clay-100)'
  }
};
function Button({
  variant = 'primary',
  size = 'md',
  icon,
  iconAfter,
  fullWidth,
  disabled,
  as = 'button',
  children,
  style,
  ...rest
}) {
  const [h, setH] = React.useState(false);
  const [p, setP] = React.useState(false);
  const s = SIZES[size] || SIZES.md;
  const El = as;
  return /*#__PURE__*/React.createElement(El, _extends({
    disabled: El === 'button' ? disabled : undefined,
    onMouseEnter: () => setH(true),
    onMouseLeave: () => {
      setH(false);
      setP(false);
    },
    onMouseDown: () => setP(true),
    onMouseUp: () => setP(false),
    style: {
      display: fullWidth ? 'flex' : 'inline-flex',
      width: fullWidth ? '100%' : undefined,
      alignItems: 'center',
      justifyContent: 'center',
      gap: s.gap,
      padding: s.padding,
      minHeight: s.minHeight,
      fontSize: s.fontSize,
      fontFamily: 'var(--font-sans)',
      fontWeight: 700,
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      borderRadius: 'var(--radius-pill)',
      cursor: disabled ? 'not-allowed' : 'pointer',
      textDecoration: 'none',
      opacity: disabled ? 0.4 : 1,
      whiteSpace: 'nowrap',
      transition: 'background var(--duration-base) var(--ease-clay), color var(--duration-base) var(--ease-clay), transform var(--duration-fast) var(--ease-clay)',
      transform: p ? 'translateY(1px)' : 'none',
      ...(VARIANTS[variant] || VARIANTS.primary),
      ...(h && !disabled ? HOVER[variant] : null),
      ...style
    }
  }, rest), icon ? /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: icon,
    size: s.icon
  }) : null, children, iconAfter ? /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: iconAfter,
    size: s.icon
  }) : null);
}
Object.assign(__ds_scope, { Button });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Button.jsx", error: String((e && e.message) || e) }); }

// components/core/IconButton.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const SIZES = {
  sm: 34,
  md: 44,
  lg: 52
};
function IconButton({
  icon,
  label,
  variant = 'ghost',
  size = 'md',
  disabled,
  style,
  ...rest
}) {
  const [h, setH] = React.useState(false);
  const d = SIZES[size] || SIZES.md;
  const base = {
    ghost: {
      background: 'transparent',
      color: 'var(--text-body)',
      border: '2px solid transparent'
    },
    outline: {
      background: 'transparent',
      color: 'var(--lissom-brown)',
      border: '2px solid var(--border-default)'
    },
    solid: {
      background: 'var(--lissom-yellow)',
      color: 'var(--lissom-brown)',
      border: '2px solid var(--lissom-yellow)'
    }
  }[variant];
  return /*#__PURE__*/React.createElement("button", _extends({
    "aria-label": label,
    disabled: disabled,
    onMouseEnter: () => setH(true),
    onMouseLeave: () => setH(false),
    style: {
      width: d,
      height: d,
      display: 'inline-grid',
      placeItems: 'center',
      borderRadius: 'var(--radius-pill)',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.4 : 1,
      ...base,
      background: h && !disabled ? variant === 'solid' ? 'var(--yellow-500)' : 'var(--clay-100)' : base.background,
      transition: 'background var(--duration-base) var(--ease-clay)',
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: icon,
    size: size === 'sm' ? 16 : size === 'lg' ? 22 : 19
  }));
}
Object.assign(__ds_scope, { IconButton });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/IconButton.jsx", error: String((e && e.message) || e) }); }

// components/core/Logo.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const SRC = {
  lockup: {
    brown: 'logo-lockup.svg',
    cream: 'logo-lockup-cream.svg',
    yellow: 'logo-lockup-yellow.svg'
  },
  wordmark: {
    brown: 'wordmark-lissom.svg',
    cream: 'wordmark-lissom-cream.svg',
    yellow: 'wordmark-lissom-cream.svg'
  },
  cup: {
    brown: 'mark-cup.svg',
    cream: 'mark-cup-cream.svg',
    yellow: 'mark-cup-yellow.svg'
  },
  heart: {
    brown: 'mark-heart-brown.png',
    cream: 'mark-heart-brown.png',
    yellow: 'mark-heart-brown.png'
  }
};
function Logo({
  variant = 'lockup',
  tone = 'brown',
  height = 56,
  assetBase = '/assets/',
  style,
  ...rest
}) {
  const file = (SRC[variant] || SRC.lockup)[tone] || SRC.lockup.brown;
  // Forholdstallet per fil. Uten det settes ikke plassen av for bildet er
  // lastet, og alt under hopper naar det kommer.
  const MAAL = {
    'wordmark-lissom.svg': [425, 86],
    'wordmark-lissom-cream.svg': [425, 86],
  };
  const m = MAAL[file];
  return /*#__PURE__*/React.createElement("img", _extends({
    src: assetBase + file,
    alt: "lissom keramikk & h\xE5ndverk",
    width: m ? m[0] : undefined,
    height: m ? m[1] : undefined,
    style: {
      height,
      width: 'auto',
      display: 'block',
      ...style
    }
  }, rest));
}
Object.assign(__ds_scope, { Logo });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Logo.jsx", error: String((e && e.message) || e) }); }

// components/core/Tag.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Tag({
  children,
  selected,
  onRemove,
  onClick,
  style,
  ...rest
}) {
  const [h, setH] = React.useState(false);
  return /*#__PURE__*/React.createElement("span", _extends({
    onClick: onClick,
    onMouseEnter: () => setH(true),
    onMouseLeave: () => setH(false),
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 6,
      padding: '7px 14px',
      borderRadius: 'var(--radius-pill)',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-sm)',
      fontWeight: 500,
      cursor: onClick ? 'pointer' : 'default',
      border: '1px solid ' + (selected ? 'var(--lissom-brown)' : h && onClick ? 'var(--lissom-brown)' : 'var(--border-default)'),
      background: selected ? 'var(--lissom-brown)' : h && onClick ? 'var(--clay-100)' : 'transparent',
      color: selected ? 'var(--clay-50)' : h && onClick ? 'var(--lissom-brown)' : 'var(--text-body)',
      transform: h && onClick && !selected ? 'translateY(-1px)' : 'none',
      transition: 'var(--transition-base)',
      ...style
    }
  }, rest), children, onRemove ? /*#__PURE__*/React.createElement("span", {
    onClick: e => {
      e.stopPropagation();
      onRemove();
    },
    style: {
      display: 'inline-flex',
      cursor: 'pointer',
      opacity: 0.6
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "x",
    size: 13
  })) : null);
}
Object.assign(__ds_scope, { Tag });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/core/Tag.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Dialog.jsx
try { (() => {
function Dialog({
  open,
  title,
  onClose,
  footer,
  width = 520,
  children,
  style
}) {
  if (!open) return null;
  return /*#__PURE__*/React.createElement("div", {
    role: "dialog",
    "aria-modal": "true",
    style: {
      position: 'fixed',
      inset: 0,
      zIndex: 100,
      display: 'grid',
      placeItems: 'center',
      background: 'rgba(46,16,2,.42)',
      backdropFilter: 'blur(3px)',
      padding: 'var(--space-6)'
    },
    onClick: onClose
  }, /*#__PURE__*/React.createElement("div", {
    onClick: e => e.stopPropagation(),
    style: {
      width: '100%',
      maxWidth: width,
      background: 'var(--surface-card)',
      borderRadius: 'var(--radius-xl)',
      boxShadow: 'var(--shadow-lg)',
      overflow: 'hidden',
      animation: 'none',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-start',
      gap: 'var(--space-4)',
      padding: 'var(--space-6) var(--space-6) var(--space-4)'
    }
  }, /*#__PURE__*/React.createElement("h3", {
    style: {
      font: 'var(--type-h3)',
      color: 'var(--text-heading)',
      margin: 0,
      flex: 1
    }
  }, title), /*#__PURE__*/React.createElement(__ds_scope.IconButton, {
    icon: "x",
    label: "Lukk",
    size: "sm",
    onClick: onClose
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '0 var(--space-6) var(--space-6)',
      font: 'var(--type-body)',
      color: 'var(--text-body)'
    }
  }, children), footer ? /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      justifyContent: 'flex-end',
      gap: 'var(--space-3)',
      padding: 'var(--space-4) var(--space-6)',
      background: 'var(--clay-100)',
      borderTop: '1px solid var(--border-subtle)'
    }
  }, footer) : null));
}
Object.assign(__ds_scope, { Dialog });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Dialog.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Toast.jsx
try { (() => {
const TONES = {
  success: {
    icon: 'check-circle-2',
    color: 'var(--success)'
  },
  info: {
    icon: 'info',
    color: 'var(--info)'
  },
  warning: {
    icon: 'alert-circle',
    color: 'var(--warning)'
  },
  danger: {
    icon: 'alert-circle',
    color: 'var(--danger)'
  }
};
function Toast({
  tone = 'success',
  title,
  description,
  onClose,
  style
}) {
  const t = TONES[tone] || TONES.success;
  return /*#__PURE__*/React.createElement("div", {
    role: "status",
    style: {
      display: 'flex',
      gap: 'var(--space-3)',
      alignItems: 'flex-start',
      minWidth: 300,
      maxWidth: 420,
      padding: 'var(--space-4)',
      background: 'var(--surface-card)',
      border: '1px solid var(--border-subtle)',
      borderRadius: 'var(--radius-lg)',
      boxShadow: 'var(--shadow-lg)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: t.color,
      display: 'flex',
      marginTop: 1
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: t.icon,
    size: 20
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-label)',
      color: 'var(--text-heading)',
      letterSpacing: 'var(--tracking-wide)'
    }
  }, title), description ? /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 'var(--text-sm)',
      color: 'var(--text-muted)',
      marginTop: 2
    }
  }, description) : null), onClose ? /*#__PURE__*/React.createElement("span", {
    onClick: onClose,
    style: {
      cursor: 'pointer',
      color: 'var(--text-faint)',
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "x",
    size: 16
  })) : null);
}
Object.assign(__ds_scope, { Toast });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Toast.jsx", error: String((e && e.message) || e) }); }

// components/feedback/Tooltip.jsx
try { (() => {
function Tooltip({
  content,
  placement = 'top',
  children,
  style
}) {
  const [open, setOpen] = React.useState(false);
  const pos = {
    top: {
      bottom: '100%',
      left: '50%',
      transform: 'translate(-50%,-8px)'
    },
    bottom: {
      top: '100%',
      left: '50%',
      transform: 'translate(-50%,8px)'
    },
    right: {
      left: '100%',
      top: '50%',
      transform: 'translate(8px,-50%)'
    },
    left: {
      right: '100%',
      top: '50%',
      transform: 'translate(-8px,-50%)'
    }
  }[placement];
  return /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'relative',
      display: 'inline-flex',
      ...style
    },
    onMouseEnter: () => setOpen(true),
    onMouseLeave: () => setOpen(false),
    onFocus: () => setOpen(true),
    onBlur: () => setOpen(false)
  }, children, /*#__PURE__*/React.createElement("span", {
    role: "tooltip",
    style: {
      position: 'absolute',
      ...pos,
      zIndex: 60,
      opacity: open ? 1 : 0,
      pointerEvents: 'none',
      whiteSpace: 'nowrap',
      background: 'var(--lissom-brown)',
      color: 'var(--clay-50)',
      fontFamily: 'var(--font-sans)',
      fontSize: 'var(--text-xs)',
      fontWeight: 500,
      padding: '6px 10px',
      borderRadius: 'var(--radius-sm)',
      boxShadow: 'var(--shadow-md)',
      transition: 'opacity var(--duration-fast) var(--ease-clay)'
    }
  }, content));
}
Object.assign(__ds_scope, { Tooltip });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/feedback/Tooltip.jsx", error: String((e && e.message) || e) }); }

// components/forms/Checkbox.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
function Checkbox({
  label,
  description,
  checked,
  onChange,
  disabled,
  style,
  ...rest
}) {
  return /*#__PURE__*/React.createElement("label", {
    style: {
      display: 'flex',
      gap: 'var(--space-3)',
      alignItems: 'flex-start',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.45 : 1,
      ...style
    }
  }, /*#__PURE__*/React.createElement("input", _extends({
    type: "checkbox",
    checked: checked,
    onChange: onChange,
    disabled: disabled,
    style: {
      position: 'absolute',
      opacity: 0,
      width: 0,
      height: 0
    }
  }, rest)), /*#__PURE__*/React.createElement("span", {
    "aria-hidden": true,
    style: {
      width: 22,
      height: 22,
      flex: '0 0 auto',
      display: 'grid',
      placeItems: 'center',
      borderRadius: 'var(--radius-sm)',
      marginTop: 1,
      border: '2px solid ' + (checked ? 'var(--lissom-brown)' : 'var(--border-default)'),
      background: checked ? 'var(--lissom-brown)' : 'var(--surface-card)',
      color: 'var(--lissom-yellow)',
      transition: 'var(--transition-base)'
    }
  }, checked ? /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "check",
    size: 14,
    strokeWidth: 3
  }) : null), /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("span", {
    style: {
      font: 'var(--type-body)',
      color: 'var(--text-body)'
    }
  }, label), description ? /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'block',
      fontSize: 'var(--text-xs)',
      color: 'var(--text-muted)'
    }
  }, description) : null));
}
Object.assign(__ds_scope, { Checkbox });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Checkbox.jsx", error: String((e && e.message) || e) }); }

// components/forms/Input.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const shell = (invalid, focused) => ({
  width: '100%',
  boxSizing: 'border-box',
  padding: 'var(--field-pad-y) var(--field-pad-x)',
  fontFamily: 'var(--font-sans)',
  fontSize: 'var(--text-base)',
  color: 'var(--text-body)',
  background: 'var(--surface-card)',
  border: '1px solid ' + (invalid ? 'var(--danger)' : focused ? 'var(--lissom-brown)' : 'var(--border-default)'),
  borderRadius: 'var(--radius-md)',
  outline: 'none',
  boxShadow: focused ? 'var(--shadow-focus)' : 'none',
  transition: 'border-color var(--duration-base) var(--ease-clay), box-shadow var(--duration-base) var(--ease-clay)'
});
const labelStyle = {
  display: 'block',
  font: 'var(--type-label)',
  letterSpacing: 'var(--tracking-caps)',
  textTransform: 'uppercase',
  color: 'var(--text-heading)',
  marginBottom: 'var(--space-2)'
};
const hintStyle = invalid => ({
  marginTop: 'var(--space-2)',
  fontSize: 'var(--text-xs)',
  color: invalid ? 'var(--danger)' : 'var(--text-muted)'
});
function Input({
  label,
  hint,
  invalid,
  icon,
  id,
  style,
  ...rest
}) {
  const [focused, setFocused] = React.useState(false);
  const uid = id || React.useId();
  return /*#__PURE__*/React.createElement("div", {
    style: {
      width: '100%',
      ...style
    }
  }, label ? /*#__PURE__*/React.createElement("label", {
    htmlFor: uid,
    style: labelStyle
  }, label) : null, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      display: 'flex',
      alignItems: 'center'
    }
  }, icon ? /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      left: 14,
      color: 'var(--text-faint)',
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: icon,
    size: 18
  })) : null, /*#__PURE__*/React.createElement("input", _extends({
    id: uid,
    onFocus: () => setFocused(true),
    onBlur: () => setFocused(false),
    style: {
      ...shell(invalid, focused),
      paddingLeft: icon ? 42 : undefined
    }
  }, rest))), hint ? /*#__PURE__*/React.createElement("div", {
    style: hintStyle(invalid)
  }, hint) : null);
}
Object.assign(__ds_scope, { Input });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Input.jsx", error: String((e && e.message) || e) }); }

// components/forms/Radio.jsx
try { (() => {
function Radio({
  label,
  description,
  checked,
  onChange,
  disabled,
  name,
  value,
  style
}) {
  return /*#__PURE__*/React.createElement("label", {
    style: {
      display: 'flex',
      gap: 'var(--space-3)',
      alignItems: 'flex-start',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.45 : 1,
      ...style
    }
  }, /*#__PURE__*/React.createElement("input", {
    type: "radio",
    name: name,
    value: value,
    checked: checked,
    onChange: onChange,
    disabled: disabled,
    style: {
      position: 'absolute',
      opacity: 0,
      width: 0,
      height: 0
    }
  }), /*#__PURE__*/React.createElement("span", {
    "aria-hidden": true,
    style: {
      width: 22,
      height: 22,
      flex: '0 0 auto',
      borderRadius: '50%',
      marginTop: 1,
      display: 'grid',
      placeItems: 'center',
      border: '2px solid ' + (checked ? 'var(--lissom-brown)' : 'var(--border-default)'),
      background: 'var(--surface-card)',
      transition: 'var(--transition-base)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 10,
      height: 10,
      borderRadius: '50%',
      background: checked ? 'var(--lissom-brown)' : 'transparent',
      transition: 'var(--transition-base)'
    }
  })), /*#__PURE__*/React.createElement("span", null, /*#__PURE__*/React.createElement("span", {
    style: {
      font: 'var(--type-body)',
      color: 'var(--text-body)'
    }
  }, label), description ? /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'block',
      fontSize: 'var(--text-xs)',
      color: 'var(--text-muted)'
    }
  }, description) : null));
}
Object.assign(__ds_scope, { Radio });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Radio.jsx", error: String((e && e.message) || e) }); }

// components/forms/Select.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const shell = (invalid, focused) => ({
  width: '100%',
  boxSizing: 'border-box',
  padding: 'var(--field-pad-y) var(--field-pad-x)',
  fontFamily: 'var(--font-sans)',
  fontSize: 'var(--text-base)',
  color: 'var(--text-body)',
  background: 'var(--surface-card)',
  border: '1px solid ' + (invalid ? 'var(--danger)' : focused ? 'var(--lissom-brown)' : 'var(--border-default)'),
  borderRadius: 'var(--radius-md)',
  outline: 'none',
  boxShadow: focused ? 'var(--shadow-focus)' : 'none',
  transition: 'border-color var(--duration-base) var(--ease-clay), box-shadow var(--duration-base) var(--ease-clay)'
});
const labelStyle = {
  display: 'block',
  font: 'var(--type-label)',
  letterSpacing: 'var(--tracking-caps)',
  textTransform: 'uppercase',
  color: 'var(--text-heading)',
  marginBottom: 'var(--space-2)'
};
const hintStyle = invalid => ({
  marginTop: 'var(--space-2)',
  fontSize: 'var(--text-xs)',
  color: invalid ? 'var(--danger)' : 'var(--text-muted)'
});
function Select({
  label,
  hint,
  invalid,
  options = [],
  id,
  style,
  ...rest
}) {
  const [focused, setFocused] = React.useState(false);
  const uid = id || React.useId();
  return /*#__PURE__*/React.createElement("div", {
    style: {
      width: '100%',
      ...style
    }
  }, label ? /*#__PURE__*/React.createElement("label", {
    htmlFor: uid,
    style: labelStyle
  }, label) : null, /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative',
      display: 'flex',
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("select", _extends({
    id: uid,
    onFocus: () => setFocused(true),
    onBlur: () => setFocused(false),
    style: {
      ...shell(invalid, focused),
      appearance: 'none',
      paddingRight: 42,
      cursor: 'pointer'
    }
  }, rest), options.map(o => {
    const v = typeof o === 'string' ? o : o.value;
    const l = typeof o === 'string' ? o : o.label;
    return /*#__PURE__*/React.createElement("option", {
      key: v,
      value: v
    }, l);
  })), /*#__PURE__*/React.createElement("span", {
    style: {
      position: 'absolute',
      right: 14,
      pointerEvents: 'none',
      color: 'var(--text-muted)',
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "chevron-down",
    size: 18
  }))), hint ? /*#__PURE__*/React.createElement("div", {
    style: hintStyle(invalid)
  }, hint) : null);
}
Object.assign(__ds_scope, { Select });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Select.jsx", error: String((e && e.message) || e) }); }

// components/forms/Switch.jsx
try { (() => {
function Switch({
  label,
  checked,
  onChange,
  disabled,
  style
}) {
  return /*#__PURE__*/React.createElement("label", {
    style: {
      display: 'inline-flex',
      gap: 'var(--space-3)',
      alignItems: 'center',
      cursor: disabled ? 'not-allowed' : 'pointer',
      opacity: disabled ? 0.45 : 1,
      ...style
    }
  }, /*#__PURE__*/React.createElement("input", {
    type: "checkbox",
    role: "switch",
    checked: checked,
    onChange: onChange,
    disabled: disabled,
    style: {
      position: 'absolute',
      opacity: 0,
      width: 0,
      height: 0
    }
  }), /*#__PURE__*/React.createElement("span", {
    "aria-hidden": true,
    style: {
      width: 46,
      height: 26,
      borderRadius: 'var(--radius-pill)',
      padding: 3,
      background: checked ? 'var(--lissom-brown)' : 'var(--clay-300)',
      transition: 'background var(--duration-base) var(--ease-clay)',
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      width: 20,
      height: 20,
      borderRadius: '50%',
      background: checked ? 'var(--lissom-yellow)' : 'var(--surface-card)',
      transform: checked ? 'translateX(20px)' : 'none',
      boxShadow: 'var(--shadow-sm)',
      transition: 'transform var(--duration-base) var(--ease-clay), background var(--duration-base) var(--ease-clay)'
    }
  })), label ? /*#__PURE__*/React.createElement("span", {
    style: {
      font: 'var(--type-body)',
      color: 'var(--text-body)'
    }
  }, label) : null);
}
Object.assign(__ds_scope, { Switch });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Switch.jsx", error: String((e && e.message) || e) }); }

// components/forms/Textarea.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const shell = (invalid, focused) => ({
  width: '100%',
  boxSizing: 'border-box',
  padding: 'var(--field-pad-y) var(--field-pad-x)',
  fontFamily: 'var(--font-sans)',
  fontSize: 'var(--text-base)',
  color: 'var(--text-body)',
  background: 'var(--surface-card)',
  border: '1px solid ' + (invalid ? 'var(--danger)' : focused ? 'var(--lissom-brown)' : 'var(--border-default)'),
  borderRadius: 'var(--radius-md)',
  outline: 'none',
  boxShadow: focused ? 'var(--shadow-focus)' : 'none',
  transition: 'border-color var(--duration-base) var(--ease-clay), box-shadow var(--duration-base) var(--ease-clay)'
});
const labelStyle = {
  display: 'block',
  font: 'var(--type-label)',
  letterSpacing: 'var(--tracking-caps)',
  textTransform: 'uppercase',
  color: 'var(--text-heading)',
  marginBottom: 'var(--space-2)'
};
const hintStyle = invalid => ({
  marginTop: 'var(--space-2)',
  fontSize: 'var(--text-xs)',
  color: invalid ? 'var(--danger)' : 'var(--text-muted)'
});
function Textarea({
  label,
  hint,
  invalid,
  rows = 4,
  id,
  style,
  ...rest
}) {
  const [focused, setFocused] = React.useState(false);
  const uid = id || React.useId();
  return /*#__PURE__*/React.createElement("div", {
    style: {
      width: '100%',
      ...style
    }
  }, label ? /*#__PURE__*/React.createElement("label", {
    htmlFor: uid,
    style: labelStyle
  }, label) : null, /*#__PURE__*/React.createElement("textarea", _extends({
    id: uid,
    rows: rows,
    onFocus: () => setFocused(true),
    onBlur: () => setFocused(false),
    style: {
      ...shell(invalid, focused),
      resize: 'vertical',
      lineHeight: 'var(--leading-normal)'
    }
  }, rest)), hint ? /*#__PURE__*/React.createElement("div", {
    style: hintStyle(invalid)
  }, hint) : null);
}
Object.assign(__ds_scope, { Textarea });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/forms/Textarea.jsx", error: String((e && e.message) || e) }); }

// components/navigation/BottomNav.jsx
try { (() => {
function BottomNav({
  items = [],
  value,
  onChange,
  style
}) {
  return /*#__PURE__*/React.createElement("nav", {
    style: {
      display: 'flex',
      background: 'var(--surface-card)',
      borderTop: '1px solid var(--border-subtle)',
      padding: '8px 4px 14px',
      ...style
    }
  }, items.map(it => {
    const on = it.value === value;
    return /*#__PURE__*/React.createElement("button", {
      key: it.value,
      onClick: () => onChange && onChange(it.value),
      style: {
        flex: 1,
        background: 'none',
        border: 0,
        cursor: 'pointer',
        display: 'grid',
        justifyItems: 'center',
        gap: 4,
        padding: '6px 0',
        minHeight: 52,
        color: on ? 'var(--lissom-brown)' : 'var(--clay-500)'
      }
    }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
      name: it.icon,
      size: 22,
      strokeWidth: on ? 2.25 : 1.75
    }), /*#__PURE__*/React.createElement("span", {
      style: {
        fontFamily: 'var(--font-sans)',
        fontWeight: 700,
        fontSize: 11,
        letterSpacing: 'var(--tracking-wide)',
        textTransform: 'uppercase'
      }
    }, it.label));
  }));
}
Object.assign(__ds_scope, { BottomNav });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/navigation/BottomNav.jsx", error: String((e && e.message) || e) }); }

// components/navigation/NavBar.jsx
try { (() => {
if (typeof document !== 'undefined' && !document.getElementById('ds-nav-anim')) {
  const st = document.createElement('style');
  st.id = 'ds-nav-anim';
  st.textContent = '@keyframes dsNavFade { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }';
  document.head.appendChild(st);
}
function NavBar({
  links = [],
  active,
  onNavigate,
  cta,
  tone = 'light',
  assetBase = '/assets/',
  sticky = true,
  overlay = false,
  style
}) {
  const ink = tone === 'ink';
  const [scrolled, setScrolled] = React.useState(false);
  const headRef = React.useRef(null);
  const [headH, setHeadH] = React.useState(168);
  const [vw, setVw] = React.useState(typeof window !== 'undefined' ? window.innerWidth : 1440);
  React.useEffect(() => {
    const onR = () => setVw(window.innerWidth);
    window.addEventListener('resize', onR);
    return () => window.removeEventListener('resize', onR);
  }, []);
  const zf = parseFloat((typeof document !== 'undefined' && document.body) ? getComputedStyle(document.body).zoom : 1) || 1;
  const evw = vw / zf;
  const compact = evw < 1400;
  const tight = evw < 1150;
  const [open, setOpen] = React.useState(false);
  React.useEffect(() => { if (!tight && open) setOpen(false); }, [tight]);
  React.useEffect(() => {
    const on = () => setScrolled(window.scrollY > 8);
    if (overlay) {
      on();
      window.addEventListener('scroll', on, { passive: true });
    }
    let ro;
    if (overlay && headRef.current && window.ResizeObserver) {
      ro = new ResizeObserver(() => {
        if (headRef.current) setHeadH(headRef.current.offsetHeight);
      });
      ro.observe(headRef.current);
    }
    return () => { window.removeEventListener('scroll', on); ro && ro.disconnect(); };
  }, [overlay]);
  return /*#__PURE__*/React.createElement("header", {
    ref: headRef,
    style: {
      position: sticky ? 'sticky' : 'static',
      top: 0,
      zIndex: 40,
      marginBottom: overlay && sticky ? -headH : 0,
      background: overlay ? (scrolled ? '#fff' : 'transparent') : ink ? 'var(--lissom-brown)' : 'var(--lissom-yellow)',
      boxShadow: overlay && scrolled ? '0 2px 16px rgba(77,29,18,.12)' : 'none',
      borderBottom: overlay ? 'none' : '1px solid ' + (ink ? 'rgba(244,235,222,.18)' : 'rgba(77,29,18,.15)'),
      transition: overlay ? 'background .3s ease, box-shadow .3s ease' : 'none',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 'var(--width-wide)',
      margin: '0 auto',
      padding: '0 var(--space-8)',
      minHeight: compact ? 108 : 168,
      display: 'flex',
      flexWrap: 'nowrap',
      alignItems: 'center',
      gap: compact ? 'var(--space-4)' : 'var(--space-6)',
      rowGap: 0
    }
  }, /*#__PURE__*/React.createElement("a", {
    href: "#",
    onClick: e => {
      e.preventDefault();
      onNavigate && onNavigate(links[0] && (links[0].value || links[0]));
    },
    style: {
      display: 'flex',
      opacity: 1,
      transition: 'opacity .2s ease'
    }
  }, /*#__PURE__*/React.createElement("span", {
    onMouseEnter: e => { const c = e.currentTarget.querySelector('[data-logo-cup]'); if (c) c.style.transform = 'perspective(600px) rotateY(720deg)'; },
    onMouseLeave: e => { const c = e.currentTarget.querySelector('[data-logo-cup]'); if (c) { c.style.transition = 'none'; c.style.transform = 'perspective(600px) rotateY(0deg)'; void c.offsetWidth; c.style.transition = 'transform 1600ms linear'; } },
    style: { display: 'flex', flexDirection: 'column', alignItems: 'center', gap: compact ? 4 : 8 }
  }, (() => {
    const cupH = compact ? (tight ? 34 : 48) : 76;
    return /*#__PURE__*/React.createElement("span", {
      style: { display: 'flex', flexDirection: 'column', alignItems: 'center', height: cupH }
    }, /*#__PURE__*/React.createElement("img", {
      src: assetBase + 'mark-cup-top.svg',
      alt: "",
      width: 192,
      height: 88,
      "data-logo-cup": true,
      style: { height: cupH * 0.786, width: 'auto', display: 'block', transition: 'transform 1600ms linear', transformOrigin: '50% 50%' }
    }), /*#__PURE__*/React.createElement("img", {
      src: assetBase + 'mark-cup-saucer.svg',
      alt: "",
      width: 192,
      height: 24,
      style: { height: cupH * 0.214, width: 'auto', display: 'block' }
    }));
  })(), /*#__PURE__*/React.createElement(__ds_scope.Logo, {
    variant: "wordmark",
    tone: ink ? 'cream' : 'brown',
    height: compact ? (tight ? 20 : 28) : 42,
    assetBase: assetBase
  }))), /*#__PURE__*/React.createElement("nav", {
    style: {
      display: tight ? 'none' : 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      gap: compact ? '4px clamp(8px, 1.2vw, 20px)' : 'var(--space-4) clamp(var(--space-3), 1.6vw, var(--space-8))',
      margin: '0 auto',
      minWidth: 0,
      flex: 1,
      overflow: 'hidden',
      flexWrap: 'nowrap'
    }
  }, links.map(l => {
    const v = typeof l === 'string' ? l : l.value;
    const label = typeof l === 'string' ? l : l.label;
    const on = v === active;
    const baseColor = ink ? (on ? 'var(--lissom-yellow)' : 'var(--clay-200)') : (on ? 'var(--lissom-brown)' : 'var(--brown-500)');
    return /*#__PURE__*/React.createElement("a", {
      key: v,
      href: "#",
      onClick: e => {
        e.preventDefault();
        onNavigate && onNavigate(v);
      },
      onMouseEnter: e => {
        const el = e.currentTarget;
        el.style.backgroundSize = '100% 2px';
        el.style.color = ink ? 'var(--lissom-yellow)' : 'var(--lissom-brown)';
      },
      onMouseLeave: e => {
        const el = e.currentTarget;
        el.style.backgroundSize = on ? '100% 2px' : '0% 2px';
        el.style.color = baseColor;
      },
      style: {
        fontFamily: 'var(--font-sans)',
        fontWeight: 700,
        fontSize: compact ? 'clamp(11px, 1.15vw, 15px)' : 'clamp(13px, 1.1vw, 19px)',
        letterSpacing: 'var(--tracking-caps)',
        textTransform: 'uppercase',
        textDecoration: 'none',
        whiteSpace: 'nowrap',
        color: baseColor,
        paddingBottom: 7,
        backgroundImage: 'linear-gradient(' + (ink ? 'var(--lissom-yellow)' : 'var(--lissom-brown)') + ', ' + (ink ? 'var(--lissom-yellow)' : 'var(--lissom-brown)') + ')',
        backgroundRepeat: 'no-repeat',
        backgroundPosition: 'left bottom',
        backgroundSize: on ? '100% 2px' : '0% 2px',
        transition: 'background-size .28s var(--ease-clay, ease), color .2s ease'
      }
    }, label);
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 'var(--space-5)',
      marginLeft: tight ? 'auto' : 'var(--space-6)'
    }
  }, tight ? /*#__PURE__*/React.createElement("button", {
    type: "button",
    "aria-label": "Meny",
    "aria-expanded": open,
    onClick: () => setOpen(o => !o),
    style: {
      appearance: 'none', background: 'transparent', border: 'none', cursor: 'pointer',
      display: 'flex', flexDirection: 'column', gap: 5, padding: 10
    }
  }, [0,1,2].map(i => /*#__PURE__*/React.createElement("span", {
    key: i,
    style: {
      width: 24, height: 3, borderRadius: 2,
      background: ink ? 'var(--lissom-yellow)' : 'var(--lissom-brown)',
      transition: 'transform .2s ease, opacity .2s ease',
      transform: open ? (i === 0 ? 'translateY(8px) rotate(45deg)' : i === 2 ? 'translateY(-8px) rotate(-45deg)' : 'none') : 'none',
      opacity: open && i === 1 ? 0 : 1
    }
  }))) : null, /*#__PURE__*/React.createElement(__ds_scope.IconButton, {
    icon: "search",
    label: "S\xF8k",
    variant: "ghost",
    size: "md",
    style: { zoom: compact ? 1 : 1.35 },
    onClick: () => onNavigate && onNavigate('Søk')
  }), cta ? /*#__PURE__*/React.createElement(__ds_scope.Button, {
    variant: ink ? 'primary' : 'ink',
    size: compact ? 'md' : 'lg',
    style: { zoom: compact ? 1 : 1.35 },
    onClick: () => onNavigate && onNavigate(cta)
  }, cta) : null)), tight && open ? /*#__PURE__*/React.createElement("nav", {
    style: {
      display: 'flex', flexDirection: 'column', alignItems: 'stretch',
      background: ink ? 'var(--lissom-brown)' : '#fff',
      borderTop: '1px solid ' + (ink ? 'rgba(244,235,222,.18)' : 'rgba(77,29,18,.12)'),
      boxShadow: '0 12px 24px rgba(77,29,18,.14)',
      padding: 'var(--space-4) var(--space-8) var(--space-6)',
      animation: 'dsNavFade .22s ease'
    }
  }, links.concat(links.some(l => (typeof l === 'string' ? l : l.value) === 'Min side') ? [] : ['Min side']).map(l => {
    const v = typeof l === 'string' ? l : l.value;
    const label = typeof l === 'string' ? l : l.label;
    const on = v === active;
    return /*#__PURE__*/React.createElement("a", {
      key: v,
      href: "#",
      onClick: e => {
        e.preventDefault();
        setOpen(false);
        onNavigate && onNavigate(v);
      },
      style: {
        fontFamily: 'var(--font-sans)', fontWeight: 700, fontSize: 16,
        letterSpacing: 'var(--tracking-caps)', textTransform: 'uppercase', textDecoration: 'none',
        color: ink ? (on ? 'var(--lissom-yellow)' : 'var(--clay-200)') : (on ? 'var(--lissom-brown)' : 'var(--brown-500)'),
        padding: '16px 4px',
        minHeight: 44,
        display: 'flex', alignItems: 'center',
        borderBottom: '1px solid ' + (ink ? 'rgba(244,235,222,.1)' : 'rgba(77,29,18,.08)')
      }
    }, label);
  })) : null);
}
Object.assign(__ds_scope, { NavBar });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/navigation/NavBar.jsx", error: String((e && e.message) || e) }); }

// components/navigation/Tabs.jsx
try { (() => {
function Tabs({
  tabs = [],
  value,
  onChange,
  style
}) {
  return /*#__PURE__*/React.createElement("div", {
    role: "tablist",
    style: {
      display: 'flex',
      gap: 'var(--space-6)',
      borderBottom: '1px solid var(--border-subtle)',
      ...style
    }
  }, tabs.map(t => {
    const v = typeof t === 'string' ? t : t.value;
    const l = typeof t === 'string' ? t : t.label;
    const active = v === value;
    return /*#__PURE__*/React.createElement("button", {
      key: v,
      role: "tab",
      "aria-selected": active,
      onClick: () => onChange && onChange(v),
      style: {
        background: 'none',
        border: 0,
        cursor: 'pointer',
        padding: '0 0 var(--space-3)',
        fontFamily: 'var(--font-sans)',
        fontWeight: 700,
        fontSize: 'var(--text-sm)',
        letterSpacing: 'var(--tracking-caps)',
        textTransform: 'uppercase',
        color: active ? 'var(--text-heading)' : 'var(--text-muted)',
        boxShadow: active ? 'inset 0 -3px 0 var(--lissom-yellow)' : 'none',
        transition: 'var(--transition-base)'
      }
    }, l);
  }));
}
Object.assign(__ds_scope, { Tabs });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/navigation/Tabs.jsx", error: String((e && e.message) || e) }); }

// components/patterns/CourseCard.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
/** Falls back to a labelled clay block when no photograph is supplied. */
function Media({
  src,
  alt
}) {
  if (src) return /*#__PURE__*/React.createElement("img", {
    src: src,
    alt: alt || '',
    style: {
      width: '100%',
      aspectRatio: '16 / 10',
      height: 'auto',
      objectFit: 'cover',
      display: 'block'
    }
  });
  return /*#__PURE__*/React.createElement("div", {
    style: {
      aspectRatio: '16 / 10',
      background: 'var(--clay-200)',
      display: 'grid',
      placeItems: 'center',
      color: 'var(--clay-400)',
      fontFamily: 'var(--font-sans)',
      fontWeight: 700,
      fontSize: 11,
      letterSpacing: 'var(--tracking-micro)'
    }
  }, "FOTO");
}
function CourseCard({
  title,
  level,
  text,
  duration,
  price,
  seats,
  status,
  date,
  image,
  ctaLabel = 'Book plass',
  onBook,
  style,
  ...rest
}) {
  const meta = [date, duration].filter(Boolean);
  return /*#__PURE__*/React.createElement(__ds_scope.Card, _extends({
    interactive: true,
    padding: "0",
    onClick: onBook ? e => { if (e && e.target && e.target.closest && e.target.closest('button')) return; onBook(); } : undefined,
    style: {
      display: 'flex',
      flexDirection: 'column',
      cursor: onBook ? 'pointer' : undefined,
      ...style
    }
  }, rest), /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement(Media, {
    src: image,
    alt: title
  }), status || seats != null ? (() => {
    const label = status || (seats <= 3 ? seats + ' plasser igjen' : 'Ledig');
    const urgent = /igjen|få plasser/i.test(label);
    return /*#__PURE__*/React.createElement("span", {
      style: {
        position: 'absolute',
        top: 14,
        left: 14,
        display: 'inline-flex',
        alignItems: 'center',
        gap: 8,
        padding: '9px 16px',
        borderRadius: 999,
        fontFamily: 'var(--font-sans)',
        fontWeight: 700,
        fontSize: 13,
        letterSpacing: 'var(--tracking-caps)',
        textTransform: 'uppercase',
        whiteSpace: 'nowrap',
        background: urgent ? 'var(--lissom-yellow)' : 'var(--lissom-brown)',
        color: urgent ? 'var(--lissom-brown)' : 'var(--clay-50)',
        boxShadow: '0 3px 14px rgba(46,16,2,.3)'
      }
    }, /*#__PURE__*/React.createElement("span", {
      style: {
        width: 8,
        height: 8,
        borderRadius: '50%',
        flex: '0 0 auto',
        background: urgent ? 'var(--lissom-brown)' : 'var(--sage-500)'
      }
    }), label);
  })() : null), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 'var(--card-pad)',
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-2)',
      flex: 1
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-eyebrow)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--terracotta-600)',
      height: 18,
      lineHeight: '18px',
      overflow: 'hidden',
      whiteSpace: 'nowrap',
      textOverflow: 'ellipsis'
    }
  }, level || '\u00A0'), /*#__PURE__*/React.createElement("h3", {
    style: {
      font: 'var(--type-h3)',
      color: 'var(--text-heading)',
      margin: 0,
      lineHeight: 1.2,
      height: '1.2em',
      whiteSpace: 'nowrap',
      overflow: 'hidden',
      textOverflow: 'ellipsis'
    }
  }, title), /*#__PURE__*/React.createElement("div", {
    style: {
      height: 40,
      overflow: 'hidden'
    }
  }, meta.length ? /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-4)',
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)',
      flexWrap: 'wrap'
    }
  }, date ? /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 6,
      whiteSpace: 'nowrap'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "calendar",
    size: 15
  }), date) : null, duration ? /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 6,
      whiteSpace: 'nowrap'
    }
  }, /*#__PURE__*/React.createElement(__ds_scope.Icon, {
    name: "clock",
    size: 15
  }), duration) : null) : null, text ? /*#__PURE__*/React.createElement("p", {
    style: {
      margin: 0,
      fontSize: 'var(--text-sm)',
      lineHeight: 1.4,
      color: 'var(--text-muted)',
      display: '-webkit-box',
      WebkitLineClamp: 2,
      WebkitBoxOrient: 'vertical',
      overflow: 'hidden',
      textWrap: 'pretty'
    }
  }, text) : null), /*#__PURE__*/React.createElement("div", {
    style: {
      marginTop: 'auto',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'space-between',
      gap: 'var(--space-3)',
      paddingTop: 'var(--space-2)',
      flexWrap: 'nowrap',
      height: 44,
      boxSizing: 'border-box',
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 800,
      fontSize: 'var(--text-xl)',
      color: 'var(--text-heading)',
      whiteSpace: 'nowrap'
    }
  }, price), /*#__PURE__*/React.createElement(__ds_scope.Button, {
    size: "sm",
    onClick: onBook
  }, ctaLabel))));
}
Object.assign(__ds_scope, { CourseCard });
})(); } catch (e) { __ds_ns.__errors.push({ path: "components/patterns/CourseCard.jsx", error: String((e && e.message) || e) }); }

// ui_kits/app/Screens.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const {
  Logo,
  Icon,
  IconButton,
  Button,
  Badge,
  Tag,
  Card,
  Divider,
  Tabs,
  Input,
  Select,
  Checkbox,
  Radio,
  Switch,
  Dialog,
  Toast,
  BottomNav,
  CourseCard
} = window.LissomDesignSystem_8402ac;
const ASSETS = '../../assets/';
const FOTO = '../../assets/photos/';
const KURS = [{
  id: 1,
  level: 'Nybegynner',
  image: FOTO + 'monica-dreier.jpg',
  title: 'Nybegynner dreiekurs',
  date: 'Starter 19. aug',
  duration: '2 økter',
  price: 'Fra 2 800 kr',
  status: 'Flere tidspunkter',
  type: 'Kurs'
}, {
  id: 2,
  level: 'Kurs',
  image: FOTO + 'dreiekurs.jpg',
  title: 'Kurs boller',
  date: 'man. 24. aug',
  duration: '17:00 – 20:00',
  price: 'Fra 1 490 kr',
  status: 'Flere tidspunkter',
  type: 'Kurs'
}, {
  id: 3,
  level: 'Kurs',
  image: FOTO + 'verkstedet.jpg',
  title: 'Store fat kurs',
  date: 'tir. 11. aug',
  duration: '10:00 – 13:00',
  price: 'Fra 1 490 kr',
  status: 'Flere tidspunkter',
  type: 'Kurs'
}, {
  id: 4,
  level: 'Event',
  image: FOTO + 'dreiekurs.jpg',
  title: 'Paint on Pots',
  date: 'Flere datoer',
  duration: 'Ca. 2 timer',
  price: 'Se datoer',
  type: 'Events'
}];
function TopBar({
  title,
  back,
  action
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 'var(--space-3)',
      padding: '14px var(--space-4)',
      background: 'var(--clay-50)',
      borderBottom: '1px solid var(--border-subtle)',
      minHeight: 60
    }
  }, back ? /*#__PURE__*/React.createElement(IconButton, {
    icon: "arrow-left",
    label: "Tilbake",
    size: "sm",
    onClick: back
  }) : /*#__PURE__*/React.createElement(Logo, {
    variant: "cup",
    tone: "brown",
    height: 26,
    assetBase: ASSETS
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-h3)',
      fontSize: 'var(--text-lg)',
      color: 'var(--text-heading)',
      flex: 1
    }
  }, title), action);
}
function Scroll({
  children,
  pad = 'var(--space-4)'
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      minHeight: 0,
      overflowY: 'auto',
      padding: pad,
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-4)'
    }
  }, React.Children.map(children, (c, i) => /*#__PURE__*/React.createElement("div", {
    key: i,
    style: {
      flex: '0 0 auto'
    }
  }, c)));
}
function Kursliste({
  open
}) {
  const [t, setT] = React.useState('Alle');
  const typer = ['Alle', 'Dreiing', 'Plateteknikk', 'Paint on pots', 'Drop-in'];
  const liste = t === 'Alle' ? KURS : KURS.filter(k => k.type === t);
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    "data-theme": "sun",
    style: {
      background: 'var(--lissom-yellow)',
      padding: 'var(--space-5) var(--space-4) var(--space-6)',
      position: 'relative',
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("img", {
    src: ASSETS + 'mark-cup.svg',
    alt: "",
    "aria-hidden": true,
    style: {
      position: 'absolute',
      right: -40,
      top: -20,
      width: 200,
      opacity: 0.1
    }
  }), /*#__PURE__*/React.createElement(Logo, {
    variant: "lockup",
    tone: "brown",
    height: 54,
    assetBase: ASSETS
  }), /*#__PURE__*/React.createElement("p", {
    style: {
      margin: 'var(--space-4) 0 0',
      color: 'var(--brown-500)',
      fontSize: 'var(--text-sm)',
      maxWidth: '30ch'
    }
  }, "Vil du pr\xF8ve keramikk? Her er alle kursene v\xE5re.")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 8,
      overflowX: 'auto',
      padding: 'var(--space-4) var(--space-4) 0'
    }
  }, typer.map(x => /*#__PURE__*/React.createElement(Tag, {
    key: x,
    selected: t === x,
    onClick: () => setT(x),
    style: {
      whiteSpace: 'nowrap'
    }
  }, x))), /*#__PURE__*/React.createElement(Scroll, null, liste.map(k => /*#__PURE__*/React.createElement(CourseCard, _extends({
    key: k.id
  }, k, {
    onBook: () => open(k)
  })))));
}
function Booking({
  kurs,
  back,
  done
}) {
  const [tid, setTid] = React.useState('a');
  const [ant, setAnt] = React.useState('1 plass');
  const [vilkar, setVilkar] = React.useState(false);
  const [dialog, setDialog] = React.useState(false);
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(TopBar, {
    title: "Book plass",
    back: back
  }), /*#__PURE__*/React.createElement(Scroll, null, /*#__PURE__*/React.createElement("img", {
    src: kurs.image || FOTO + 'dreiekurs.jpg',
    alt: kurs.title,
    style: {
      height: 190,
      width: '100%',
      objectFit: 'cover',
      borderRadius: 'var(--radius-lg)'
    }
  }), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-eyebrow)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--terracotta-600)'
    }
  }, kurs.level), /*#__PURE__*/React.createElement("h2", {
    style: {
      margin: '6px 0 0',
      fontSize: 'var(--text-2xl)'
    }
  }, kurs.title)), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-4)',
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 6,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "calendar",
    size: 15
  }), kurs.date), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 6,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "clock",
    size: 15
  }), kurs.duration)), /*#__PURE__*/React.createElement("p", {
    style: {
      margin: 0,
      fontSize: 'var(--text-sm)'
    }
  }, "Alt av leire, verkt\xF8y og brenning er inkludert. Maks \xE5tte plasser."), /*#__PURE__*/React.createElement(Divider, {
    ornament: true
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-label)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--text-heading)'
    }
  }, "Velg tid"), /*#__PURE__*/React.createElement(Radio, {
    name: "tid",
    label: kurs.date,
    description: kurs.duration,
    checked: tid === 'a',
    onChange: () => setTid('a')
  }), /*#__PURE__*/React.createElement(Radio, {
    name: "tid",
    label: "Neste dato",
    description: "Fullbooket",
    disabled: true,
    checked: tid === 'b',
    onChange: () => setTid('b')
  }), /*#__PURE__*/React.createElement(Select, {
    label: "Antall",
    options: ['1 plass', '2 plasser', '3 plasser'],
    value: ant,
    onChange: e => setAnt(e.target.value)
  }), /*#__PURE__*/React.createElement(Input, {
    label: "E-post",
    icon: "mail",
    placeholder: "navn@epost.no"
  }), /*#__PURE__*/React.createElement(Checkbox, {
    label: "Jeg har lest vilk\xE5rene",
    checked: vilkar,
    onChange: e => setVilkar(e.target.checked)
  })), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: 'var(--space-4)',
      borderTop: '1px solid var(--border-subtle)',
      background: 'var(--surface-card)',
      display: 'flex',
      alignItems: 'center',
      gap: 'var(--space-4)'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      fontSize: 11,
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--text-muted)'
    }
  }, "Totalt"), /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 800,
      fontSize: 'var(--text-xl)',
      color: 'var(--text-heading)'
    }
  }, kurs.price)), /*#__PURE__*/React.createElement(Button, {
    style: {
      flex: 1
    },
    disabled: !vilkar,
    onClick: () => setDialog(true)
  }, "Book plass")), /*#__PURE__*/React.createElement(Dialog, {
    open: dialog,
    title: "Bekreft p\xE5melding",
    onClose: () => setDialog(false),
    width: 330,
    footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Button, {
      variant: "ghost",
      size: "sm",
      onClick: () => setDialog(false)
    }, "Avbryt"), /*#__PURE__*/React.createElement(Button, {
      size: "sm",
      onClick: () => {
        setDialog(false);
        done();
      }
    }, "Betal ", kurs.price))
  }, kurs.title, ", ", kurs.date, " kl. 18:00. ", ant, "."));
}
function MinePlasser({
  booked
}) {
  const [t, setT] = React.useState('Kommende');
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(TopBar, {
    title: "Mine plasser"
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      padding: '0 var(--space-4)'
    }
  }, /*#__PURE__*/React.createElement(Tabs, {
    tabs: ['Kommende', 'Tidligere'],
    value: t,
    onChange: setT
  })), /*#__PURE__*/React.createElement(Scroll, null, t === 'Kommende' ? booked.length ? booked.map((k, i) => /*#__PURE__*/React.createElement(Card, {
    key: i,
    padding: "var(--card-pad)"
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      justifyContent: 'space-between',
      alignItems: 'flex-start',
      gap: 12
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h3", {
    style: {
      margin: '0 0 6px',
      fontSize: 'var(--text-lg)'
    }
  }, k.title), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)',
      display: 'flex',
      gap: 12
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 6,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "calendar",
    size: 14
  }), k.date), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 6,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "map-pin",
    size: 14
  }), "N\xF8tter\xF8y"))), /*#__PURE__*/React.createElement(Badge, {
    tone: "success"
  }, "Bekreftet")), /*#__PURE__*/React.createElement(Divider, {
    style: {
      margin: 'var(--space-4) 0'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-3)'
    }
  }, /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    size: "sm",
    style: {
      flex: 1
    }
  }, "Vis billett"), /*#__PURE__*/React.createElement(Button, {
    variant: "ghost",
    size: "sm"
  }, "Avbestill")))) : /*#__PURE__*/React.createElement("div", {
    style: {
      textAlign: 'center',
      padding: 'var(--space-16) var(--space-6)',
      color: 'var(--text-muted)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--clay-400)',
      display: 'inline-flex'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "ticket",
    size: 40
  })), /*#__PURE__*/React.createElement("p", {
    style: {
      margin: 'var(--space-4) 0 0',
      fontSize: 'var(--text-sm)'
    }
  }, "Du har ingen kommende plasser.")) : /*#__PURE__*/React.createElement(Card, {
    padding: "var(--card-pad)"
  }, /*#__PURE__*/React.createElement("h3", {
    style: {
      margin: '0 0 6px',
      fontSize: 'var(--text-lg)'
    }
  }, "Paint on pots \u2013 familie"), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)'
    }
  }, "s\xF8n 11. mai \xB7 fullf\xF8rt"))));
}
function Medlem() {
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(TopBar, {
    title: "Medlemskap"
  }), /*#__PURE__*/React.createElement(Scroll, null, /*#__PURE__*/React.createElement(Card, {
    tone: "sun",
    elevation: "none",
    padding: "var(--card-pad-lg)"
  }, /*#__PURE__*/React.createElement(Badge, {
    tone: "ink"
  }, "Fast plass"), /*#__PURE__*/React.createElement("div", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 800,
      fontSize: 'var(--text-4xl)',
      color: 'var(--lissom-brown)',
      margin: 'var(--space-4) 0 4px'
    }
  }, "890 kr"), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--brown-500)',
      fontSize: 'var(--text-sm)'
    }
  }, "per m\xE5ned \xB7 fornyes 1. oktober")), [['Egen hylle i verkstedet', 'flower'], ['Fire kvelder i måneden', 'calendar'], ['10 kg leire inkludert', 'shopping-bag'], ['Brenning inkludert', 'check']].map(([t, i]) => /*#__PURE__*/React.createElement("div", {
    key: t,
    style: {
      display: 'flex',
      gap: 12,
      alignItems: 'center',
      fontSize: 'var(--text-sm)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--sage-600)',
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: i,
    size: 18
  })), t)), /*#__PURE__*/React.createElement(Divider, {
    ornament: true
  }), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    fullWidth: true
  }, "Endre medlemskap")));
}
function Profil() {
  const [sms, setSms] = React.useState(true);
  const [nyhet, setNyhet] = React.useState(false);
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(TopBar, {
    title: "Meg"
  }), /*#__PURE__*/React.createElement(Scroll, null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-4)',
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("img", {
    src: FOTO + 'monica-portrett.jpg',
    alt: "",
    style: {
      width: 64,
      height: 64,
      borderRadius: '50%',
      objectFit: 'cover'
    }
  }), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("h3", {
    style: {
      margin: 0,
      fontSize: 'var(--text-lg)'
    }
  }, "Ingrid Hansen"), /*#__PURE__*/React.createElement("div", {
    style: {
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)'
    }
  }, "ingrid@epost.no"))), /*#__PURE__*/React.createElement(Divider, null), /*#__PURE__*/React.createElement(Input, {
    label: "Navn",
    defaultValue: "Ingrid Hansen"
  }), /*#__PURE__*/React.createElement(Input, {
    label: "Telefon",
    defaultValue: "+47 94 13 46 01",
    icon: "phone"
  }), /*#__PURE__*/React.createElement(Divider, null), /*#__PURE__*/React.createElement(Switch, {
    label: "P\xE5minnelse p\xE5 SMS",
    checked: sms,
    onChange: e => setSms(e.target.checked)
  }), /*#__PURE__*/React.createElement(Switch, {
    label: "Nyhetsbrev",
    checked: nyhet,
    onChange: e => setNyhet(e.target.checked)
  }), /*#__PURE__*/React.createElement(Divider, null), /*#__PURE__*/React.createElement(Button, {
    variant: "ghost",
    icon: "log-out",
    fullWidth: true
  }, "Logg ut"), /*#__PURE__*/React.createElement("div", {
    style: {
      textAlign: 'center',
      paddingTop: 'var(--space-6)'
    }
  }, /*#__PURE__*/React.createElement(Logo, {
    variant: "lockup",
    tone: "brown",
    height: 40,
    assetBase: ASSETS,
    style: {
      margin: '0 auto',
      opacity: .35
    }
  }))));
}
Object.assign(window, {
  KURS,
  TopBar,
  Scroll,
  Kursliste,
  Booking,
  MinePlasser,
  Medlem,
  Profil,
  ASSETS
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/app/Screens.jsx", error: String((e && e.message) || e) }); }

// ui_kits/app/app.jsx
try { (() => {
const {
  BottomNav,
  Toast
} = window.LissomDesignSystem_8402ac;
function App() {
  const [tab, setTab] = React.useState('kurs');
  const [kurs, setKurs] = React.useState(null);
  const [booked, setBooked] = React.useState([]);
  const [toast, setToast] = React.useState(false);
  const done = () => {
    setBooked(b => [...b, kurs]);
    setKurs(null);
    setTab('mine');
    setToast(true);
    setTimeout(() => setToast(false), 4000);
  };
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement("div", {
    style: {
      flex: 1,
      display: 'flex',
      flexDirection: 'column',
      overflow: 'hidden'
    }
  }, kurs ? /*#__PURE__*/React.createElement(Booking, {
    kurs: kurs,
    back: () => setKurs(null),
    done: done
  }) : /*#__PURE__*/React.createElement(React.Fragment, null, tab === 'kurs' ? /*#__PURE__*/React.createElement(Kursliste, {
    open: setKurs
  }) : null, tab === 'mine' ? /*#__PURE__*/React.createElement(MinePlasser, {
    booked: booked
  }) : null, tab === 'medlem' ? /*#__PURE__*/React.createElement(Medlem, null) : null, tab === 'meg' ? /*#__PURE__*/React.createElement(Profil, null) : null)), !kurs ? /*#__PURE__*/React.createElement(BottomNav, {
    value: tab,
    onChange: setTab,
    items: [{
      value: 'kurs',
      label: 'Kurs',
      icon: 'calendar'
    }, {
      value: 'mine',
      label: 'Mine',
      icon: 'ticket'
    }, {
      value: 'medlem',
      label: 'Medlem',
      icon: 'star'
    }, {
      value: 'meg',
      label: 'Meg',
      icon: 'circle-user'
    }]
  }) : null, toast ? /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'absolute',
      left: 16,
      right: 16,
      top: 16,
      zIndex: 200
    }
  }, /*#__PURE__*/React.createElement(Toast, {
    tone: "success",
    title: "Plassen er reservert",
    description: "Du f\xE5r e-post om to minutter.",
    onClose: () => setToast(false)
  })) : null);
}
ReactDOM.createRoot(document.getElementById('root')).render(/*#__PURE__*/React.createElement(App, null));
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/app/app.jsx", error: String((e && e.message) || e) }); }

// ui_kits/website/Forside.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const {
  Button,
  CourseCard,
  Card,
  Badge,
  Icon,
  Logo,
  Divider
} = window.LissomDesignSystem_8402ac;
function Hero({
  go
}) {
  return /*#__PURE__*/React.createElement("section", {
    "data-theme": "sun",
    style: {
      background: 'var(--lissom-yellow)',
      position: 'relative',
      overflow: 'hidden'
    }
  }, /*#__PURE__*/React.createElement("img", {
    src: ASSETS + 'mark-cup.svg',
    alt: "",
    "aria-hidden": true,
    style: {
      position: 'absolute',
      right: -110,
      top: -60,
      width: 620,
      opacity: 0.09,
      pointerEvents: 'none'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 'var(--width-content)',
      margin: '0 auto',
      padding: '120px var(--space-8) 128px',
      position: 'relative'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-eyebrow)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--brown-500)'
    }
  }, "Keramikkverksted p\xE5 N\xF8tter\xF8y"), /*#__PURE__*/React.createElement("h1", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 800,
      fontSize: 'var(--text-6xl)',
      lineHeight: 'var(--leading-tight)',
      letterSpacing: 'var(--tracking-display)',
      color: 'var(--lissom-brown)',
      margin: 'var(--space-6) 0 var(--space-6)',
      maxWidth: '14ch'
    }
  }, "Vil du pr\xF8ve keramikk?"), /*#__PURE__*/React.createElement("p", {
    style: {
      font: 'var(--type-body)',
      fontSize: 'var(--text-lg)',
      color: 'var(--brown-500)',
      maxWidth: '46ch'
    }
  }, "Et kreativt fristed for skaperglede, h\xE5ndverk og fellesskap. Du trenger ingen forkunnskaper \u2014 bare lyst til \xE5 lage noe med egne hender."), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-4)',
      marginTop: 'var(--space-8)'
    }
  }, /*#__PURE__*/React.createElement(Button, {
    variant: "ink",
    size: "lg",
    iconAfter: "arrow-right",
    onClick: () => go('Kurs')
  }, "Se kursene"), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    size: "lg",
    onClick: () => go('Medlemskap')
  }, "Bli medlem"))));
}
const FOTO = '../../assets/photos/';
const KURS = [{
  level: 'Nybegynner',
  title: 'Nybegynner dreiekurs',
  date: 'Starter 19. aug',
  duration: '2 økter, 2 dager',
  price: 'Fra 2 800 kr',
  status: 'Flere tidspunkter',
  image: FOTO + 'monica-dreier.jpg'
}, {
  level: 'Kurs',
  title: 'Kurs boller',
  date: 'man. 24. aug',
  duration: '17:00 – 20:00',
  price: 'Fra 1 490 kr',
  status: 'Flere tidspunkter',
  image: FOTO + 'dreiekurs.jpg'
}, {
  level: 'Kurs',
  title: 'Store fat kurs',
  date: 'tir. 11. aug',
  duration: '10:00 – 13:00',
  price: 'Fra 1 490 kr',
  status: 'Flere tidspunkter',
  image: FOTO + 'verkstedet.jpg'
}];
function Fakta() {
  const items = [{
    icon: 'flower',
    t: 'Alt er inkludert',
    d: 'Leire, verktøy, glasur og brenning.'
  }, {
    icon: 'users',
    t: 'Ingen forkunnskaper',
    d: 'Vi veileder deg gjennom hele prosessen.'
  }, {
    icon: 'gift',
    t: 'Gavekort',
    d: 'Kan brukes på kurs, events, medlemskap og verkstedtid.'
  }];
  return /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3,1fr)',
      gap: 'var(--space-8)'
    }
  }, items.map(i => /*#__PURE__*/React.createElement("div", {
    key: i.t
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--terracotta-500)',
      display: 'flex',
      marginBottom: 'var(--space-4)'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: i.icon,
    size: 28
  })), /*#__PURE__*/React.createElement("h3", {
    style: {
      font: 'var(--type-h3)',
      margin: '0 0 var(--space-2)'
    }
  }, i.t), /*#__PURE__*/React.createElement("p", {
    style: {
      font: 'var(--type-body-sm)',
      color: 'var(--text-muted)',
      margin: 0
    }
  }, i.d))));
}
function Forside({
  go
}) {
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Hero, {
    go: go
  }), /*#__PURE__*/React.createElement(Section, null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'flex-end',
      justifyContent: 'space-between',
      marginBottom: 'var(--space-10)'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(Eyebrow, null, "Neste kurs"), /*#__PURE__*/React.createElement("h2", {
    style: {
      margin: 0
    }
  }, "Kommende datoer")), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    size: "sm",
    iconAfter: "arrow-right",
    onClick: () => go('Kurs')
  }, "Alle kurs")), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3,1fr)',
      gap: 'var(--space-6)'
    }
  }, KURS.map(k => /*#__PURE__*/React.createElement(CourseCard, _extends({
    key: k.title
  }, k, {
    onBook: () => go('Kurs')
  }))))), /*#__PURE__*/React.createElement(Section, {
    tone: "clay"
  }, /*#__PURE__*/React.createElement(Fakta, null)), /*#__PURE__*/React.createElement(Section, null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '1fr 1fr',
      gap: 'var(--space-16)',
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement("img", {
    src: FOTO + 'kursrommet.jpg',
    alt: "Kursrommet p\xE5 Teie",
    style: {
      height: 460,
      width: '100%',
      objectFit: 'cover',
      borderRadius: 'var(--radius-lg)'
    }
  }), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(Eyebrow, null, "Om oss"), /*#__PURE__*/React.createElement("h2", null, "Et verksted, ikke en butikk"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: 'var(--text-body)'
    }
  }, "Lissom holder til p\xE5 Teie p\xE5 N\xF8tter\xF8y, med verksted, kurslokaler og en liten butikk. Her handler keramikk om mer enn leire: \xE5 senke skuldrene, v\xE6re til stede i \xF8yeblikket og oppleve mestring gjennom skapende arbeid."), /*#__PURE__*/React.createElement("p", {
    style: {
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)'
    }
  }, "Nordre L\xF8kkevei 15, 3120 N\xF8tter\xF8y"), /*#__PURE__*/React.createElement(Button, {
    variant: "secondary",
    style: {
      marginTop: 'var(--space-4)'
    }
  }, "Finn fram")))), /*#__PURE__*/React.createElement(Section, {
    tone: "ink"
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'center',
      gap: 'var(--space-10)',
      justifyContent: 'center',
      textAlign: 'center',
      flexDirection: 'column'
    }
  }, /*#__PURE__*/React.createElement("img", {
    src: ASSETS + 'mark-heart-brown.png',
    alt: "",
    style: {
      height: 56,
      filter: 'brightness(0) saturate(100%) invert(84%) sepia(38%) saturate(1000%) hue-rotate(340deg) brightness(105%)'
    }
  }), /*#__PURE__*/React.createElement("h2", {
    style: {
      color: 'var(--clay-50)',
      margin: 0,
      fontSize: 'var(--text-4xl)'
    }
  }, "100% h\xE5ndlaget med kj\xE6rlighet"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: 'var(--clay-300)',
      margin: 0,
      maxWidth: '48ch'
    }
  }, "Keramikk til mat og drikke brennes med godkjente glasurer, p\xE5 riktig temperatur. De fleste produktene t\xE5ler oppvaskmaskin."))));
}
Object.assign(window, {
  Forside,
  KURS
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/website/Forside.jsx", error: String((e && e.message) || e) }); }

// ui_kits/website/Kurs.jsx
try { (() => {
function _extends() { return _extends = Object.assign ? Object.assign.bind() : function (n) { for (var e = 1; e < arguments.length; e++) { var t = arguments[e]; for (var r in t) ({}).hasOwnProperty.call(t, r) && (n[r] = t[r]); } return n; }, _extends.apply(null, arguments); }
const {
  CourseCard,
  Tag,
  Button,
  Badge,
  Card,
  Icon,
  Input,
  Select,
  Checkbox,
  Radio,
  Dialog,
  Toast,
  Divider
} = window.LissomDesignSystem_8402ac;
const FOTO = '../../assets/photos/';
// Real programme, read from lissom-kurs.understory.io and lissom-events.understory.io (Aug 2026).
const ALLE = [{
  level: 'Nybegynner',
  image: FOTO + 'monica-dreier.jpg',
  title: 'Nybegynner dreiekurs',
  date: 'Starter 19. aug',
  duration: '2 økter, 2 dager',
  price: 'Fra 2 800 kr',
  status: 'Flere tidspunkter',
  type: 'Kurs'
}, {
  level: 'Kurs',
  image: FOTO + 'dreiekurs.jpg',
  title: 'Kurs boller',
  date: 'man. 24. aug',
  duration: '17:00 – 20:00',
  price: 'Fra 1 490 kr',
  status: 'Flere tidspunkter',
  type: 'Kurs'
}, {
  level: 'Kurs',
  image: FOTO + 'verkstedet.jpg',
  title: 'Store fat kurs',
  date: 'tir. 11. aug',
  duration: '10:00 – 13:00',
  price: 'Fra 1 490 kr',
  status: 'Flere tidspunkter',
  type: 'Kurs'
}, {
  level: 'Workshop',
  image: FOTO + 'dreiekurs.jpg',
  title: 'Keramikk Workshop',
  duration: 'Etter avtale',
  price: 'Fra 1 490 kr',
  type: 'Kurs'
}, {
  level: 'Event',
  image: FOTO + 'verkstedet.jpg',
  title: 'Date night',
  duration: 'Kveld, for to',
  price: 'Se datoer',
  type: 'Events'
}, {
  level: 'Event',
  image: FOTO + 'dreiekurs.jpg',
  title: 'Paint on Pots',
  duration: 'Ca. 2 timer',
  price: 'Se datoer',
  type: 'Events'
}, {
  level: 'Medlemskap',
  image: FOTO + 'monica-dreier.jpg',
  title: 'Prøv Lissom – 30 dager',
  duration: '30 dager',
  price: 'Fra 390 kr',
  type: 'Medlemskap'
}];
const TYPER = ['Alle', 'Kurs', 'Events', 'Medlemskap'];
function Kursoversikt({
  open
}) {
  const [t, setT] = React.useState('Alle');
  const liste = t === 'Alle' ? ALLE : ALLE.filter(k => k.type === t);
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Section, {
    tone: "clay",
    style: {
      padding: 'var(--space-16) var(--space-8)'
    }
  }, /*#__PURE__*/React.createElement(Eyebrow, null, "Kurs"), /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: '0 0 var(--space-6)'
    }
  }, "Kurs, events og medlemskap"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: 'var(--text-muted)',
      margin: 0
    }
  }, "Leire, verkt\xF8y og brenning er inkludert. Du trenger ingen forkunnskaper.")), /*#__PURE__*/React.createElement(Section, null, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      gap: 'var(--space-3)',
      flexWrap: 'wrap',
      marginBottom: 'var(--space-10)'
    }
  }, TYPER.map(x => /*#__PURE__*/React.createElement(Tag, {
    key: x,
    selected: t === x,
    onClick: () => setT(x)
  }, x))), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3,1fr)',
      gap: 'var(--space-6)'
    }
  }, liste.map(k => /*#__PURE__*/React.createElement(CourseCard, _extends({
    key: k.title
  }, k, {
    onBook: () => open(k)
  }))))));
}
function Kursdetalj({
  kurs,
  back
}) {
  const [tid, setTid] = React.useState('a');
  const [ant, setAnt] = React.useState('1 plass');
  const [vilkar, setVilkar] = React.useState(false);
  const [dialog, setDialog] = React.useState(false);
  const [toast, setToast] = React.useState(false);
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Section, {
    style: {
      paddingTop: 'var(--space-10)',
      paddingBottom: 'var(--space-6)'
    }
  }, /*#__PURE__*/React.createElement("a", {
    href: "#",
    onClick: e => {
      e.preventDefault();
      back();
    },
    style: {
      display: 'inline-flex',
      alignItems: 'center',
      gap: 6,
      fontSize: 'var(--text-sm)',
      textDecoration: 'none'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "arrow-left",
    size: 16
  }), " Tilbake")), /*#__PURE__*/React.createElement(Section, {
    style: {
      paddingTop: 0
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: '1.25fr 1fr',
      gap: 'var(--space-12)',
      alignItems: 'start'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("img", {
    src: kurs.image || FOTO + 'dreiekurs.jpg',
    alt: kurs.title,
    style: {
      height: 440,
      width: '100%',
      objectFit: 'cover',
      borderRadius: 'var(--radius-lg)',
      marginBottom: 'var(--space-8)'
    }
  }), /*#__PURE__*/React.createElement(Eyebrow, null, kurs.level, " \xB7 ", kurs.duration), /*#__PURE__*/React.createElement("h1", {
    style: {
      marginTop: 0
    }
  }, kurs.title), /*#__PURE__*/React.createElement("p", null, "Du trenger ingen forkunnskaper. Vi veileder deg gjennom hele prosessen, og du f\xE5r hjelp til \xE5 velge glasur og teknikk. N\xE5r du er ferdig m\xE5 arbeidene t\xF8rke, brennes, glaseres og brennes p\xE5 nytt. Vi gir beskjed n\xE5r keramikken kan hentes."), /*#__PURE__*/React.createElement(Divider, {
    ornament: true,
    style: {
      margin: 'var(--space-8) 0'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3,1fr)',
      gap: 'var(--space-6)'
    }
  }, [['calendar', kurs.date || 'Flere datoer'], ['clock', kurs.duration], ['map-pin', 'Nordre Løkkevei 15, Teie']].map(([i, v]) => /*#__PURE__*/React.createElement("div", {
    key: v,
    style: {
      display: 'flex',
      gap: 10,
      alignItems: 'center',
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: 'var(--terracotta-500)',
      display: 'flex'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: i,
    size: 18
  })), v)))), /*#__PURE__*/React.createElement(Card, {
    padding: "var(--card-pad-lg)",
    elevation: "md",
    style: {
      position: 'sticky',
      top: 108
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'baseline',
      justifyContent: 'space-between',
      marginBottom: 'var(--space-5)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 800,
      fontSize: 'var(--text-3xl)',
      color: 'var(--text-heading)'
    }
  }, kurs.price), /*#__PURE__*/React.createElement(Badge, {
    tone: "neutral"
  }, kurs.status || 'Flere tidspunkter')), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-4)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-label)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--text-heading)'
    }
  }, "Velg tid"), /*#__PURE__*/React.createElement(Radio, {
    name: "tid",
    label: (kurs.date || 'Første ledige') + (kurs.duration ? ' · ' + kurs.duration : ''),
    description: "Ledige plasser",
    checked: tid === 'a',
    onChange: () => setTid('a')
  }), /*#__PURE__*/React.createElement(Radio, {
    name: "tid",
    label: "Neste dato",
    description: "Fullbooket",
    disabled: true,
    checked: tid === 'b',
    onChange: () => setTid('b')
  }), /*#__PURE__*/React.createElement(Select, {
    label: "Antall",
    options: ['1 plass', '2 plasser', '3 plasser'],
    value: ant,
    onChange: e => setAnt(e.target.value)
  }), /*#__PURE__*/React.createElement(Input, {
    label: "E-post",
    icon: "mail",
    placeholder: "navn@epost.no"
  }), /*#__PURE__*/React.createElement(Checkbox, {
    label: "Jeg har lest vilk\xE5rene",
    checked: vilkar,
    onChange: e => setVilkar(e.target.checked)
  }), /*#__PURE__*/React.createElement(Button, {
    fullWidth: true,
    size: "lg",
    onClick: () => setDialog(true),
    disabled: !vilkar
  }, "Book plass"), /*#__PURE__*/React.createElement("p", {
    style: {
      fontSize: 'var(--text-xs)',
      color: 'var(--text-muted)',
      margin: 0,
      textAlign: 'center'
    }
  }, "Avbestilling inntil 48 timer f\xF8r."))))), /*#__PURE__*/React.createElement(Dialog, {
    open: dialog,
    title: "Bekreft p\xE5melding",
    onClose: () => setDialog(false),
    footer: /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Button, {
      variant: "ghost",
      size: "sm",
      onClick: () => setDialog(false)
    }, "Avbryt"), /*#__PURE__*/React.createElement(Button, {
      size: "sm",
      onClick: () => {
        setDialog(false);
        setToast(true);
        setTimeout(() => setToast(false), 4000);
      }
    }, "Betal ", kurs.price))
  }, kurs.title, kurs.date ? ', ' + kurs.date : '', ". ", ant, "."), toast ? /*#__PURE__*/React.createElement("div", {
    style: {
      position: 'fixed',
      right: 24,
      bottom: 24,
      zIndex: 200
    }
  }, /*#__PURE__*/React.createElement(Toast, {
    tone: "success",
    title: "Plassen er reservert",
    description: "Du f\xE5r e-post om to minutter.",
    onClose: () => setToast(false)
  })) : null);
}
Object.assign(window, {
  Kursoversikt,
  Kursdetalj,
  ALLE
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/website/Kurs.jsx", error: String((e && e.message) || e) }); }

// ui_kits/website/Medlemskap.jsx
try { (() => {
const {
  Card,
  Button,
  Badge,
  Icon,
  Divider,
  Tabs
} = window.LissomDesignSystem_8402ac;

// Read from lissom-medlemskap.understory.io and lissom.no/sporsmal-og-svar (Aug 2026).
// Månedspris for fullt medlemskap er ikke offentlig — den står bevisst som "Se priser".
const PLANER = [{
  navn: 'Prøv Lissom',
  pris: 'Fra 390 kr',
  per: '30 dager',
  tone: 'paper',
  punkter: ['Tilgang til verkstedet i 30 dager', 'Booking av verkstedtid', 'Ukentlig veiledning', 'Kaffe og te']
}, {
  navn: 'Medlemskap',
  pris: 'Se priser',
  per: 'per måned',
  tone: 'sun',
  anbefalt: true,
  punkter: ['Verksted åpent 24 timer i døgnet, 7 dager i uken', 'Enkel online booking av verkstedtid', 'Gratis ukentlig veiledning', 'Medlemskvelder, markeder og åpne hus', 'Mulighet til å stille ut og selge egen keramikk på utvalgte arrangementer']
}, {
  navn: 'Drop-in',
  pris: 'Kun med medlem',
  per: 'per besøk',
  tone: 'paper',
  punkter: ['Tilgang til verkstedet sammen med et medlem', 'Leire og materialer', 'Brenning', 'Én ferdig gjenstand på inntil 20 × 20 cm']
}];
const SPORSMAL = [['Kan jeg bli medlem selv om jeg aldri har drevet med keramikk før?', 'Nei. Du må ha tatt et av våre kurs eller ha tidligere erfaring med keramikk for å bli medlem.'], ['Er verkstedet åpent hele døgnet?', 'Ja. Alle medlemmer har tilgang 24 timer i døgnet, 7 dager i uken, så lenge medlemskapet er aktivt.'], ['Hvordan fungerer booking av verkstedtid?', 'Medlemmer booker verkstedtid gjennom bookingsystemet vårt. Slik sikrer vi gode arbeidsforhold og nok plass til alle.'], ['Kan jeg ta med en venn til verkstedet?', 'Ja, gjennom drop-in-ordningen. Drop-in er kun tilgjengelig sammen med et aktivt medlem, som må være til stede under hele besøket.'], ['Kan jeg selge keramikk jeg lager hos Lissom?', 'Medlemskapene er laget for hobbybruk og gir ikke rett til kommersiell produksjon. På utvalgte markeder og arrangementer kan medlemmer stille ut og selge egen keramikk.'], ['Har dere kaffe?', 'Ja, alltid. Vi har kaffe og te tilgjengelig, og på kurs og arrangementer tilbyr vi enkel servering.']];
function Medlemskap() {
  const [t, setT] = React.useState('Medlemskap');
  return /*#__PURE__*/React.createElement(React.Fragment, null, /*#__PURE__*/React.createElement(Section, {
    tone: "clay",
    style: {
      padding: 'var(--space-16) var(--space-8)'
    }
  }, /*#__PURE__*/React.createElement(Eyebrow, null, "Medlemskap"), /*#__PURE__*/React.createElement("h1", {
    style: {
      margin: '0 0 var(--space-6)'
    }
  }, "Fortsett i ditt eget tempo"), /*#__PURE__*/React.createElement("p", {
    style: {
      color: 'var(--text-muted)',
      margin: 0,
      maxWidth: '52ch'
    }
  }, "Har du tatt et kurs og vil videre? Som medlem f\xE5r du tilgang til verkstedet, veiledning og et kreativt fellesskap.")), /*#__PURE__*/React.createElement(Section, null, /*#__PURE__*/React.createElement(Tabs, {
    tabs: ['Medlemskap', 'Prøv 30 dager'],
    value: t,
    onChange: setT,
    style: {
      marginBottom: 'var(--space-10)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(3,1fr)',
      gap: 'var(--space-6)',
      alignItems: 'start'
    }
  }, PLANER.map(p => /*#__PURE__*/React.createElement(Card, {
    key: p.navn,
    tone: p.tone,
    elevation: p.tone === 'sun' ? 'none' : 'sm',
    padding: "var(--card-pad-lg)"
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      justifyContent: 'space-between',
      alignItems: 'center',
      marginBottom: 'var(--space-4)'
    }
  }, /*#__PURE__*/React.createElement("h3", {
    style: {
      margin: 0
    }
  }, p.navn), p.anbefalt ? /*#__PURE__*/React.createElement(Badge, {
    tone: "ink"
  }, "Mest valgt") : null), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      alignItems: 'baseline',
      gap: 8,
      marginBottom: 'var(--space-5)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      fontFamily: 'var(--font-display)',
      fontWeight: 800,
      fontSize: 'var(--text-3xl)',
      color: 'var(--lissom-brown)'
    }
  }, p.pris), /*#__PURE__*/React.createElement("span", {
    style: {
      fontSize: 'var(--text-sm)',
      color: p.tone === 'sun' ? 'var(--brown-500)' : 'var(--text-muted)'
    }
  }, p.per)), /*#__PURE__*/React.createElement(Divider, {
    style: {
      marginBottom: 'var(--space-5)',
      borderColor: p.tone === 'sun' ? 'rgba(77,29,18,.2)' : undefined
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 'var(--space-3)',
      marginBottom: 'var(--space-6)'
    }
  }, p.punkter.map(x => /*#__PURE__*/React.createElement("div", {
    key: x,
    style: {
      display: 'flex',
      gap: 10,
      alignItems: 'flex-start',
      fontSize: 'var(--text-sm)',
      color: p.tone === 'sun' ? 'var(--brown-500)' : 'var(--text-body)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      color: p.tone === 'sun' ? 'var(--lissom-brown)' : 'var(--sage-600)',
      display: 'flex',
      marginTop: 2,
      flex: '0 0 auto'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "check",
    size: 16,
    strokeWidth: 2.5
  })), x))), /*#__PURE__*/React.createElement(Button, {
    fullWidth: true,
    variant: p.tone === 'sun' ? 'ink' : 'secondary'
  }, p.navn === 'Drop-in' ? 'Les mer' : 'Velg ' + p.navn.toLowerCase()))))), /*#__PURE__*/React.createElement(Section, {
    tone: "clay"
  }, /*#__PURE__*/React.createElement(Eyebrow, null, "Sp\xF8rsm\xE5l og svar"), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'grid',
      gridTemplateColumns: 'repeat(2,1fr)',
      gap: 'var(--space-10)',
      marginTop: 'var(--space-8)'
    }
  }, SPORSMAL.map(([q, a]) => /*#__PURE__*/React.createElement("div", {
    key: q
  }, /*#__PURE__*/React.createElement("h3", {
    style: {
      margin: '0 0 var(--space-2)',
      fontSize: 'var(--text-lg)'
    }
  }, q), /*#__PURE__*/React.createElement("p", {
    style: {
      margin: 0,
      color: 'var(--text-muted)',
      fontSize: 'var(--text-sm)'
    }
  }, a))))));
}
Object.assign(window, {
  Medlemskap
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/website/Medlemskap.jsx", error: String((e && e.message) || e) }); }

// ui_kits/website/Shell.jsx
try { (() => {
const {
  NavBar,
  Logo,
  Icon,
  Divider
} = window.LissomDesignSystem_8402ac;
const ASSETS = '../../assets/';
const LINKS = ['Våre kurs', 'Events', 'Medlemskap', 'Om oss'];
function Section({
  tone = 'page',
  children,
  style
}) {
  const bg = {
    page: 'var(--clay-50)',
    clay: 'var(--clay-100)',
    sun: 'var(--lissom-yellow)',
    ink: 'var(--lissom-brown)'
  }[tone];
  return /*#__PURE__*/React.createElement("section", {
    "data-theme": tone === 'ink' ? 'ink' : tone === 'sun' ? 'sun' : undefined,
    style: {
      background: bg,
      padding: 'var(--section-y) var(--space-8)',
      ...style
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 'var(--width-content)',
      margin: '0 auto'
    }
  }, children));
}
function Eyebrow({
  children,
  color = 'var(--terracotta-600)'
}) {
  return /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-eyebrow)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color,
      marginBottom: 'var(--space-4)'
    }
  }, children);
}
function Footer() {
  return /*#__PURE__*/React.createElement("footer", {
    "data-theme": "ink",
    style: {
      background: 'var(--lissom-brown)',
      color: 'var(--clay-200)',
      padding: 'var(--space-20) var(--space-8) var(--space-10)'
    }
  }, /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 'var(--width-content)',
      margin: '0 auto',
      display: 'grid',
      gridTemplateColumns: '1.2fr 1fr 1fr',
      gap: 'var(--space-16)',
      alignItems: 'start'
    }
  }, /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement(Logo, {
    variant: "lockup",
    tone: "yellow",
    height: 72,
    assetBase: ASSETS
  }), /*#__PURE__*/React.createElement("p", {
    style: {
      marginTop: 'var(--space-6)',
      fontSize: 'var(--text-sm)',
      color: 'var(--clay-300)',
      maxWidth: '30ch'
    }
  }, "Nordre L\xF8kkevei 15, 3120 N\xF8tter\xF8y", /*#__PURE__*/React.createElement("br", null), "Teie, N\xF8tter\xF8y")), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-label)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--lissom-yellow)',
      marginBottom: 'var(--space-4)'
    }
  }, "Tilbud"), ['Kurs', 'Events', 'Medlemskap', 'Gavekort', 'Spørsmål og svar'].map(x => /*#__PURE__*/React.createElement("div", {
    key: x,
    style: {
      marginBottom: 8
    }
  }, /*#__PURE__*/React.createElement("a", {
    href: "#",
    style: {
      color: 'var(--clay-200)',
      textDecoration: 'none',
      fontSize: 'var(--text-sm)'
    }
  }, x)))), /*#__PURE__*/React.createElement("div", null, /*#__PURE__*/React.createElement("div", {
    style: {
      font: 'var(--type-label)',
      letterSpacing: 'var(--tracking-caps)',
      textTransform: 'uppercase',
      color: 'var(--lissom-yellow)',
      marginBottom: 'var(--space-4)'
    }
  }, "Kontakt"), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      flexDirection: 'column',
      gap: 10,
      fontSize: 'var(--text-sm)'
    }
  }, /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 8,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "phone",
    size: 15
  }), "+47 94 13 46 01"), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 8,
      alignItems: 'center'
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "mail",
    size: 15
  }), "monica@lissom.no"), /*#__PURE__*/React.createElement("span", {
    style: {
      display: 'inline-flex',
      gap: 12,
      marginTop: 6
    }
  }, /*#__PURE__*/React.createElement(Icon, {
    name: "instagram",
    size: 18
  }), /*#__PURE__*/React.createElement(Icon, {
    name: "facebook",
    size: 18
  }))))), /*#__PURE__*/React.createElement("div", {
    style: {
      maxWidth: 'var(--width-content)',
      margin: '0 auto'
    }
  }, /*#__PURE__*/React.createElement(Divider, {
    style: {
      margin: 'var(--space-12) 0 var(--space-6)',
      borderColor: 'rgba(244,235,222,.18)'
    }
  }), /*#__PURE__*/React.createElement("div", {
    style: {
      display: 'flex',
      justifyContent: 'space-between',
      fontSize: 'var(--text-2xs)',
      letterSpacing: 'var(--tracking-micro)',
      textTransform: 'uppercase',
      color: 'var(--clay-400)'
    }
  }, /*#__PURE__*/React.createElement("span", null, "WWW.LISSOM.NO"), /*#__PURE__*/React.createElement("span", null, "ORG. NR. 342 122 236 MVA"))));
}
Object.assign(window, {
  Section,
  Eyebrow,
  Footer,
  ASSETS,
  LINKS,
  NavBar
});
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/website/Shell.jsx", error: String((e && e.message) || e) }); }

// ui_kits/website/app.jsx
try { (() => {
function App() {
  const [page, setPage] = React.useState('Forside');
  const [kurs, setKurs] = React.useState(null);
  const go = p => {
    setKurs(null);
    setPage(p);
    window.scrollTo(0, 0);
  };
  const open = k => {
    setKurs(k);
    setPage('Kursdetalj');
    window.scrollTo(0, 0);
  };
  return /*#__PURE__*/React.createElement("div", {
    style: {
      background: 'var(--clay-50)'
    }
  }, /*#__PURE__*/React.createElement(NavBar, {
    links: LINKS,
    active: page === 'Forside' ? null : page === 'Kursdetalj' ? 'Kurs' : page,
    cta: "Book n\xE5",
    assetBase: ASSETS,
    onNavigate: v => go(v === 'Om oss' || v === 'Drop-in' ? 'Kurs' : v)
  }), page === 'Forside' ? /*#__PURE__*/React.createElement(Forside, {
    go: go
  }) : null, page === 'Kurs' ? /*#__PURE__*/React.createElement(Kursoversikt, {
    open: open
  }) : null, page === 'Kursdetalj' && kurs ? /*#__PURE__*/React.createElement(Kursdetalj, {
    kurs: kurs,
    back: () => go('Kurs')
  }) : null, page === 'Medlemskap' ? /*#__PURE__*/React.createElement(Medlemskap, null) : null, /*#__PURE__*/React.createElement(Footer, null));
}
ReactDOM.createRoot(document.getElementById('root')).render(/*#__PURE__*/React.createElement(App, null));
})(); } catch (e) { __ds_ns.__errors.push({ path: "ui_kits/website/app.jsx", error: String((e && e.message) || e) }); }

__ds_ns.Badge = __ds_scope.Badge;

__ds_ns.Button = __ds_scope.Button;

__ds_ns.Card = __ds_scope.Card;

__ds_ns.Divider = __ds_scope.Divider;

__ds_ns.Icon = __ds_scope.Icon;

__ds_ns.IconButton = __ds_scope.IconButton;

__ds_ns.Logo = __ds_scope.Logo;

__ds_ns.Tag = __ds_scope.Tag;

__ds_ns.Dialog = __ds_scope.Dialog;

__ds_ns.Toast = __ds_scope.Toast;

__ds_ns.Tooltip = __ds_scope.Tooltip;

__ds_ns.Checkbox = __ds_scope.Checkbox;

__ds_ns.Input = __ds_scope.Input;

__ds_ns.Radio = __ds_scope.Radio;

__ds_ns.Select = __ds_scope.Select;

__ds_ns.Switch = __ds_scope.Switch;

__ds_ns.Textarea = __ds_scope.Textarea;

__ds_ns.BottomNav = __ds_scope.BottomNav;

__ds_ns.NavBar = __ds_scope.NavBar;

__ds_ns.Tabs = __ds_scope.Tabs;

__ds_ns.CourseCard = __ds_scope.CourseCard;

})();
