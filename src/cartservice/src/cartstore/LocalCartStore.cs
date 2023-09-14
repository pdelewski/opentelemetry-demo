// Copyright The OpenTelemetry Authors
// SPDX-License-Identifier: Apache-2.0
using System;
using System.Collections.Concurrent;
using System.Diagnostics;
using System.Linq;
using System.Threading.Tasks;

namespace cartservice.cartstore;

internal class LocalCartStore : ICartStore
{
    private static readonly log4net.ILog log = log4net.LogManager.GetLogger(typeof(LocalCartStore));
    // Maps between user and their cart
    private readonly ConcurrentDictionary<string, Oteldemo.Cart> _userCartItems = new();
    private readonly Oteldemo.Cart _emptyCart = new();

    public Task InitializeAsync()
    {
        log.InfoFormat("Local Cart Store was initialized");
        return Task.CompletedTask;
    }

    public Task AddItemAsync(string userId, string productId, int quantity)
    {
        log.InfoFormat("AddItemAsync called with userId={0}, productId={1}, quantity={2}", userId, productId, quantity);
        var newCart = new Oteldemo.Cart
        {
            UserId = userId,
            Items = { new Oteldemo.CartItem { ProductId = productId, Quantity = quantity } }
        };
        _userCartItems.AddOrUpdate(userId, newCart,
            (_, exVal) =>
            {
                // If the item exists, we update its quantity
                var existingItem = exVal.Items.SingleOrDefault(item => item.ProductId == productId);
                if (existingItem != null)
                {
                    existingItem.Quantity += quantity;
                }
                else
                {
                    exVal.Items.Add(new Oteldemo.CartItem { ProductId = productId, Quantity = quantity });
                }

                return exVal;
            });

        return Task.CompletedTask;
    }

    public Task EmptyCartAsync(string userId)
    {
        var eventTags = new ActivityTagsCollection {{"userId", userId}};
        Activity.Current?.AddEvent(new ActivityEvent("EmptyCartAsync called.", default, eventTags));

        _userCartItems[userId] = new Oteldemo.Cart();
        return Task.CompletedTask;
    }

    public Task<Oteldemo.Cart> GetCartAsync(string userId)
    {
        log.InfoFormat("GetCartAsync called with userId={0}", userId);
        if (!_userCartItems.TryGetValue(userId, out var cart))
        {
            log.InfoFormat("No carts for user {0}", 0);
            return Task.FromResult(_emptyCart);
        }

        return Task.FromResult(cart);
    }

    public bool Ping()
    {
        return true;
    }
}
