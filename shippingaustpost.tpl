<form action="{$form_post}" method="post">
    <fieldset>
        <legend>{l s='Australia Post Shipping Configuration'}</legend>
        <label for="default_weight">Default Weight:</label>
        <div class="margin-form">
            <input type="text" name="default_weight" value="{$default_weight}" /> g
            <p>Default weight for products with no weight defined</p>
        </div>
        <label for="packing_weight">Package Weight:</label>
        <div class="margin-form">
            <input type="text" name="packing_weight" value="{$packing_weight}" /> g
            <p>Default Package Weight (Added to product weights)</p>
        </div>
        <label for="packing_weight_percent">Package Weight Percent:</label>
        <div class="margin-form">
            <input type="text" name="packing_weight_percent" value="{$packing_weight_percent}" /> %
            <p>Percent of Product weight to add as packaging</p>
        </div>
        <label for="packing_weight_percent">Allow multiple packages:</label>
        <div class="margin-form">
            <input type="checkbox" name="multi" class="noborder" {if $packing_multi}checked="checked"{/if} />
            <p>
                Split packages over {$max_kgs}kg, otherwise carrier will be unavailable.<br />
                Calculation assumes boxes can be filled to max weight. (experimental)
            </p>
        </div>
    </fieldset>
    <br />
    <fieldset>
        <legend>{l s='Australia Post Shipping Enabled Services'}</legend>
        <span>{l s='Click to enable services'}</span><br /><br />
        <table cellspacing="0" cellpadding="0" class="table" style="width: 29.5em;">
            <thead>
                <tr>
                    <th><input type="checkbox" name="checkme" class="noborder" onclick="checkDelBoxes(this.form, 'services[]', this.checked)" /></th>
                    <th>{l s='Name'}</th>
                    <th>{l s='Delay'}</th>
                </tr>
            </thead>
            <tbody>
            {foreach from=$service_types item=service}
                <tr>
                    <td><input type="checkbox" class="noborder" value="{$service.type}" name="services[]") {if $service.id_carrier}checked="checked"{/if}></td>
                    <td>{$service.name}</td>
                    <td>{$service.delay}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
        <br />
        <p>Note: After adding services you must add zones into the carrier.</p>
    </fieldset>
    <br />
    <input type="submit" name="btnUpdate" class="button" value="{l s='Update'}">
</form>