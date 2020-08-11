const pages = {
  cart: import("./pages/cart/index"),
  popin: import("./pages/popin/index"),
  product: import("./pages/product/index"),
}

async function renderPages(name) {
  const page = await pages[name];
  return page.render()
}
