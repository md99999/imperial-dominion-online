<?php
/** Market: post lots, buy what other rulers have posted, withdraw your own. */
if (!defined('ABSPATH')) exit;
/** @var object $kingdom */
$items    = IDO_Market::tradeable();
$filter   = isset($_GET['item']) ? sanitize_key(wp_unslash($_GET['item'])) : '';
if ($filter !== '' && !isset($items[$filter])) $filter = '';
$listings = IDO_Market::listings((int) $kingdom->round_id, $filter, 100);
$mine     = IDO_Market::my_listings($kingdom);
$tax      = IDO_Settings::int('market_tax_percent');
?>
<div class="ido-panel">
    <h3 class="ido-panel-title">Post a lot</h3>
    <p class="ido-dim">
        Goods leave your stores the moment you post them and come back if the lot expires or you withdraw it.
        The crown takes <?php echo esc_html((string) $tax); ?>% of every sale. Posting costs no turns.
    </p>
    <?php echo IDO_UI::form_open('market_post', 'ido-form-inline'); ?>
        <label class="ido-field ido-field-small">
            <span>Goods</span>
            <select name="item" class="ido-select" id="ido-post-item">
                <?php $first_hint = ''; ?>
                <?php foreach ($items as $key => $item) : ?>
                    <?php
                    $hint = IDO_Market::price_hint((int) $kingdom->round_id, $key);
                    if ($first_hint === '') $first_hint = $hint;
                    ?>
                    <option value="<?php echo esc_attr($key); ?>" data-hint="<?php echo esc_attr($hint); ?>">
                        <?php echo esc_html(sprintf('%s (%s held)', $item['label'], IDO_Game::fmt($kingdom->{$item['column']}))); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="ido-field ido-field-small">
            <span>Quantity</span>
            <?php echo IDO_UI::number_field('qty', 0, 1); ?>
        </label>
        <label class="ido-field ido-field-small">
            <span>Gold each</span>
            <?php echo IDO_UI::number_field('unit_price', 0, 1); ?>
        </label>
        <button type="submit" class="ido-btn">Post</button>
        <?php /* Updated by the script as the goods change; correct without it too. */ ?>
        <span class="ido-price-hint ido-dim" id="ido-price-hint"><?php echo esc_html($first_hint); ?></span>
    </form>
</div>

<?php if ($mine) : ?>
<div class="ido-panel">
    <h3 class="ido-panel-title">Your lots</h3>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Goods</th><th class="ido-right">Quantity</th><th class="ido-right">Gold each</th><th>Expires</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($mine as $listing) : ?>
            <tr>
                <td><?php echo esc_html($items[$listing->item_key]['label'] ?? $listing->item_key); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($listing->qty)); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($listing->unit_price)); ?></td>
                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' H:i', strtotime($listing->expires_at))); ?></td>
                <td>
                    <?php echo IDO_UI::form_open('market_withdraw', 'ido-form-inline'); ?>
                        <input type="hidden" name="listing_id" value="<?php echo esc_attr((string) $listing->id); ?>">
                        <button type="submit" class="ido-btn ido-btn-alt ido-btn-small">Withdraw</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="ido-panel">
    <h3 class="ido-panel-title">Open lots</h3>
    <p class="ido-filters">
        <a class="ido-btn ido-btn-small <?php echo $filter === '' ? 'ido-btn-alt' : ''; ?>" href="<?php echo esc_url(IDO_UI::url('market')); ?>">All</a>
        <?php foreach ($items as $key => $item) : ?>
            <a class="ido-btn ido-btn-small <?php echo $filter === $key ? 'ido-btn-alt' : ''; ?>"
               href="<?php echo esc_url(IDO_UI::url('market', ['item' => $key])); ?>"><?php echo esc_html($item['label']); ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!$listings) : ?>
        <p class="ido-dim">The stalls are empty. Post something and name your price.</p>
    <?php else : ?>
    <table class="ido-table ido-table-wide">
        <thead><tr><th>Goods</th><th>Seller</th><th class="ido-right">Quantity</th><th class="ido-right">Gold each</th><th>Buy</th></tr></thead>
        <tbody>
        <?php foreach ($listings as $listing) : ?>
            <tr>
                <td><?php echo esc_html($items[$listing->item_key]['label'] ?? $listing->item_key); ?></td>
                <td><?php echo esc_html((string) $listing->kingdom_name); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($listing->qty)); ?></td>
                <td class="ido-right"><?php echo esc_html(IDO_Game::fmt($listing->unit_price)); ?></td>
                <td>
                    <?php if ((int) $listing->seller_kingdom_id === (int) $kingdom->id) : ?>
                        <span class="ido-dim">Your lot</span>
                    <?php else : ?>
                        <?php echo IDO_UI::form_open('market_buy', 'ido-form-inline'); ?>
                            <input type="hidden" name="listing_id" value="<?php echo esc_attr((string) $listing->id); ?>">
                            <?php echo IDO_UI::number_field('qty', (int) $listing->qty, 1); ?>
                            <button type="submit" class="ido-btn ido-btn-small">Buy</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
